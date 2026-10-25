<?php

namespace App\Services\Voting;

use App\Enums\BallotScope;
use App\Enums\ElectionStatus;
use App\Enums\RoundStatus;
use App\Enums\WaveStatus;
use App\Models\Election;
use App\Models\Round;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Devices\DeviceManager;
use App\Services\InternalNotifier;
use App\Services\Permits\PermitManager;
use App\Services\Results\ResultPublication;
use App\Services\Voters\EligibilitySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat status pemilihan berubah (bagian 4.2 dokumen Tahap 1).
 */
class ElectionLifecycle
{
    public function __construct(
        private AuditLogger $audit,
        private WaveManager $waves,
    ) {}

    /**
     * Masalah yang menghalangi DRAFT -> READY. Kosong berarti siap.
     *
     * @return array<int, string>
     */
    public function readinessProblems(Election $election): array
    {
        $problems = [];
        $ballots = $election->ballots()->withCount('ballotCandidates')->get();

        if ($ballots->isEmpty()) {
            $problems[] = 'Belum ada surat suara.';
        }

        foreach ($ballots as $ballot) {
            if ($ballot->ballot_candidates_count === 0) {
                $problems[] = "Surat suara \"{$ballot->title}\" belum punya kandidat.";
            }

            if ($ballot->max_candidates === null) {
                continue;
            }

            if ($ballot->scope !== BallotScope::PerRt) {
                if ($ballot->ballot_candidates_count > $ballot->max_candidates) {
                    $problems[] = "Surat suara \"{$ballot->title}\" melebihi batas {$ballot->max_candidates} kandidat.";
                }

                continue;
            }

            // Surat suara per RT: batas berlaku untuk masing-masing RT, bukan total semua RT.
            $overLimitUnitIds = $ballot->ballotCandidates()->reorder()->toBase()
                ->groupBy('unit_id')
                ->havingRaw('count(*) > ?', [$ballot->max_candidates])
                ->pluck('unit_id');

            foreach (Unit::query()->whereIn('id', $overLimitUnitIds)->orderBy('sort')->pluck('name') as $unitName) {
                $problems[] = "Surat suara \"{$ballot->title}\" {$unitName} melebihi batas {$ballot->max_candidates} kandidat per RT.";
            }
        }

        return $problems;
    }

    /**
     * Peringatan yang tidak menghalangi (mis. kandidat tanpa foto).
     *
     * @return array<int, string>
     */
    public function readinessWarnings(Election $election): array
    {
        $warnings = [];
        $withoutPhoto = $election->ballots()->withCount(['candidates as without_photo_count' => fn ($query) => $query->whereNull('photo_key')])->get()->sum('without_photo_count');

        if ($withoutPhoto > 0) {
            $warnings[] = "{$withoutPhoto} kandidat belum punya foto (akan tampil inisial).";
        }

        if ($election->isDadakan() && ! $election->staff()->exists()) {
            $warnings[] = 'Belum ada Panitia/Petugas Pintu yang ditugaskan.';
        }

        return $warnings;
    }

    public function markReady(Election $election, User $actor): void
    {
        $this->transition($election, [ElectionStatus::Draft], ElectionStatus::Ready, $actor, function (Election $election): void {
            $problems = $this->readinessProblems($election);

            if ($problems !== []) {
                throw VotingException::invalidState(implode(' ', $problems));
            }

            // Slot Meja/Bilik dibuat saat Siap agar laptop bisa dipasang sebelum hari H.
            if (! $election->isDadakan()) {
                app(DeviceManager::class)->ensureSlots($election);
            }
        });
    }

    public function backToDraft(Election $election, User $actor): void
    {
        $this->transition($election, [ElectionStatus::Ready], ElectionStatus::Draft, $actor);
    }

    public function start(Election $election, User $actor): void
    {
        $this->transition($election, [ElectionStatus::Ready], ElectionStatus::Berlangsung, $actor, function (Election $election): void {
            $otherLive = Election::query()
                ->whereKeyNot($election->id)
                ->whereIn('status', [ElectionStatus::Berlangsung, ElectionStatus::Paused])
                ->exists();

            if ($otherLive) {
                throw VotingException::invalidState('Masih ada pemilihan lain yang berlangsung (K13).');
            }

            $problems = $this->readinessProblems($election);

            if ($problems !== []) {
                throw VotingException::invalidState(implode(' ', $problems));
            }

            $election->started_at = Carbon::now();

            if (! $election->isDadakan()) {
                $counts = app(EligibilitySnapshot::class)->freeze($election);

                if (array_sum($counts) === 0) {
                    throw VotingException::invalidState('Belum ada pemilih berhak untuk surat suara mana pun. Periksa data pemilih dan cakupan surat suara.');
                }

                app(DeviceManager::class)->ensureSlots($election);
            }

            $round = new Round;
            $round->election()->associate($election);
            $round->number = 1;
            $round->status = RoundStatus::Dibuka;
            $round->opened_at = Carbon::now();
            $round->save();
        }, meta: fn (Election $election): array => [
            'attendees' => $election->attendees()->count(),
            'ballots' => $election->ballots()->count(),
            'eligible_per_ballot' => $election->ballots()->withCount('voterEntries')->pluck('voter_entries_count', 'title')->all(),
        ]);
    }

    public function pause(Election $election, User $actor, ?string $note = null): void
    {
        $this->transition($election, [ElectionStatus::Berlangsung], ElectionStatus::Paused, $actor, function (Election $election): void {
            $this->waves->pause($election);
        }, note: $note);
    }

    public function resume(Election $election, User $actor): void
    {
        $this->transition($election, [ElectionStatus::Paused], ElectionStatus::Berlangsung, $actor, function (Election $election): void {
            $this->waves->resume($election);
        });
    }

    /**
     * Menutup pemilihan. Gelombang ditutup, putaran ditutup, dan tautan peserta-suara
     * Mode Dadakan dihapus permanen (setelah ini Pulihkan Hak Pilih tidak lagi mungkin).
     */
    public function close(Election $election, User $actor): void
    {
        $this->transition($election, [ElectionStatus::Berlangsung, ElectionStatus::Paused], ElectionStatus::Ditutup, $actor, function (Election $election): void {
            $now = Carbon::now();

            foreach ($election->rounds as $round) {
                $round->waves()->whereIn('status', [WaveStatus::Dibuka, WaveStatus::Dijeda])->update([
                    'status' => WaveStatus::Ditutup,
                    'closed_at' => $now,
                    'paused_remaining_seconds' => null,
                ]);

                if ($round->status === RoundStatus::Dibuka) {
                    $round->status = RoundStatus::Ditutup;
                    $round->closed_at = $now;
                    $round->save();
                }
            }

            $election->closed_at = $now;

            // Mode Dadakan: tautan peserta–suara (voter_link) disimpan untuk Detail Suara saat sengketa,
            // lalu dihapus oleh DataRetention setelah masa sengketa (keputusan panitia, menggantikan K25 Opsi A).
            if (! $election->isDadakan()) {
                // Mode Resmi: suara yang belum dikonfirmasi tidak tersimpan; semua laptop dilepas (K14, K29).
                app(PermitManager::class)->stopAll($election, 'PEMILIHAN_DITUTUP');
                app(DeviceManager::class)->releaseAll($election);
            }
        }, meta: fn (Election $election): array => [
            'valid_votes' => $election->votes()->where('status', 'SAH')->count(),
        ]);
    }

    /**
     * Membuka kembali pemungutan untuk putaran berikutnya (dipanggil NextRoundService).
     *
     * @param  callable(Election): void  $prepare
     */
    public function startNextRound(Election $election, User $actor, callable $prepare, string $reason): void
    {
        $this->transition($election, [ElectionStatus::Ditutup, ElectionStatus::Verifikasi], ElectionStatus::Berlangsung, $actor, function (Election $election) use ($prepare): void {
            $otherLive = Election::query()
                ->whereKeyNot($election->id)
                ->whereIn('status', [ElectionStatus::Berlangsung, ElectionStatus::Paused])
                ->exists();

            if ($otherLive) {
                throw VotingException::invalidState('Masih ada pemilihan lain yang berlangsung (K13).');
            }

            $prepare($election);
        }, note: $reason);
    }

    public function startVerification(Election $election, User $actor): void
    {
        $this->transition($election, [ElectionStatus::Ditutup], ElectionStatus::Verifikasi, $actor);
    }

    /**
     * Publikasi hasil resmi. Syarat: semua penetapan diisi dan semua berita acara disahkan (K08, K21).
     */
    public function publish(Election $election, User $actor): void
    {
        $this->transition($election, [ElectionStatus::Verifikasi, ElectionStatus::Unpublished], ElectionStatus::Published, $actor, function (Election $election): void {
            $problems = app(ResultPublication::class)->publishProblems($election);

            if ($problems !== []) {
                throw VotingException::invalidState(implode(' ', $problems));
            }

            // Awal masa sengketa untuk retensi K26 dihitung dari publikasi pertama.
            $election->published_at ??= Carbon::now();

            // Salinan PIN untuk tabel panitia dihapus saat hasil diumumkan (bukan saat ditutup, karena putaran
            // berikutnya untuk hasil seri dibuka setelah ditutup dan warga memakai PIN yang sama).
            $election->attendees()->whereNotNull('pin_encrypted')->update(['pin_encrypted' => null]);
        });

        app(InternalNotifier::class)->notifySuperAdmins('Hasil dipublikasikan', "{$actor->name} mempublikasikan hasil \"{$election->name}\".", $actor);
    }

    public function unpublish(Election $election, User $actor, string $note): void
    {
        $this->transition($election, [ElectionStatus::Published], ElectionStatus::Unpublished, $actor, note: $note);

        app(InternalNotifier::class)->notifySuperAdmins('Hasil ditarik dari publik', "{$actor->name} menarik hasil \"{$election->name}\". Alasan: {$note}", $actor);
    }

    public function reopenVerification(Election $election, User $actor): void
    {
        $this->transition($election, [ElectionStatus::Unpublished], ElectionStatus::Verifikasi, $actor);
    }

    /**
     * Status yang boleh "Ulang dari awal": sedang berjalan (gladi malam sebelum hari H, tanpa perlu Tutup), ditutup,
     * dibatalkan, atau sudah diumumkan (gladi sampai pengumuman untuk mencocokkan hasil dengan quick count; keputusan
     * pemilik 2026-10-25). Pengumuman yang terhapus diberitahukan ke semua Super Admin dan tercatat di audit log.
     */
    public const RESETTABLE = [ElectionStatus::Berlangsung, ElectionStatus::Paused, ElectionStatus::Ditutup, ElectionStatus::Verifikasi, ElectionStatus::Published, ElectionStatus::Unpublished, ElectionStatus::Cancelled];

    /**
     * Ulang dari awal (untuk gladi, permintaan pemilik 2026-10-23/25): hapus semua jejak voting pemilihan ini (suara, izin,
     * koreksi, jeda TPS, peserta hadir, daftar beku, hasil, berita acara, putaran) lalu kembali ke Draf. Calon, surat
     * suara, data pemilih, akun, dan pasangan laptop meja/bilik tetap: laptop yang dipasang untuk gladi langsung siap
     * lagi setelah Tandai Siap (laptop yang sudah dilepas saat Tutup tetap harus dipasang ulang). Hanya Super Admin;
     * jejaknya tetap tercatat di audit log (yang tidak ikut dihapus) dan semua Super Admin diberi tahu.
     *
     * @return array<string, int> jumlah yang dihapus per jenis
     */
    public function resetToDraft(Election $election, User $actor): array
    {
        abort_unless($actor->isSuperAdmin(), 403);

        $counts = DB::transaction(function () use ($election): array {
            $locked = Election::query()->whereKey($election->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, self::RESETTABLE, true)) {
                throw VotingException::invalidState('Hanya pemilihan yang sudah dimulai yang bisa diulang dari awal.');
            }

            $ballotIds = $locked->ballots()->pluck('id');
            $wasPublished = $locked->published_at !== null;
            $counts = ['suara' => DB::table('votes')->where('election_id', $locked->id)->count(), 'sudah_diumumkan' => (int) $wasPublished];

            // Urutan mengikuti kunci asing: yang merujuk dihapus lebih dulu.
            DB::table('vote_corrections')->where('election_id', $locked->id)->delete();
            DB::table('vote_cancellations')->where('election_id', $locked->id)->delete();
            DB::table('votes')->where('election_id', $locked->id)->delete();
            $counts['izin'] = DB::table('permits')->where('election_id', $locked->id)->delete();
            DB::table('tps_pauses')->where('election_id', $locked->id)->delete();
            $counts['peserta_hadir'] = DB::table('attendees')->where('election_id', $locked->id)->delete();
            DB::table('ballot_voters')->whereIn('ballot_id', $ballotIds)->delete();
            DB::table('outcomes')->where('election_id', $locked->id)->delete();
            DB::table('official_reports')->where('election_id', $locked->id)->delete();
            DB::table('rounds')->where('election_id', $locked->id)->delete();
            // Pasangan laptop tetap (hanya catatan "terakhir aktif" yang dikosongkan).
            DB::table('devices')->where('election_id', $locked->id)->update(['last_seen_at' => null]);

            $locked->forceFill([
                'status' => ElectionStatus::Draft,
                'started_at' => null,
                'closed_at' => null,
                'published_at' => null,
                'results_revealed_at' => null,
                'vote_links_destroyed_at' => null,
                'personal_data_purged_at' => null,
            ])->save();

            $election->setRawAttributes($locked->getAttributes(), true);

            return $counts;
        });

        $this->audit->log('election.reset', $election, $election, meta: $counts, actor: $actor);
        $this->waves->publishStatus($election);

        app(InternalNotifier::class)->notifySuperAdmins(
            'Pemilihan diulang dari awal',
            "{$actor->name} mengulang \"{$election->name}\" dari awal: {$counts['suara']} suara dihapus"
                .($counts['sudah_diumumkan'] ? ', hasil yang sudah diumumkan ikut dihapus dari halaman publik.' : '.'),
            $actor,
        );

        return $counts;
    }

    public function cancel(Election $election, User $actor, string $note): void
    {
        $allowed = [ElectionStatus::Draft, ElectionStatus::Ready, ElectionStatus::Berlangsung, ElectionStatus::Paused, ElectionStatus::Ditutup, ElectionStatus::Verifikasi];

        $this->transition($election, $allowed, ElectionStatus::Cancelled, $actor, function (Election $election): void {
            if ($election->isDadakan()) {
                DB::table('votes')->where('election_id', $election->id)->update(['voter_link' => null]);
                $election->vote_links_destroyed_at = Carbon::now();
                $election->attendees()->whereNotNull('pin_encrypted')->update(['pin_encrypted' => null]);
            }
        }, note: $note);
    }

    /**
     * @param  array<int, ElectionStatus>  $from
     * @param  (callable(Election): void)|null  $guard
     * @param  (callable(Election): array<string, mixed>)|null  $meta
     */
    private function transition(Election $election, array $from, ElectionStatus $to, User $actor, ?callable $guard = null, ?callable $meta = null, ?string $note = null): void
    {
        $previous = null;

        DB::transaction(function () use ($election, $from, $to, $guard, &$previous): void {
            $locked = Election::query()->whereKey($election->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw VotingException::invalidState("Tidak bisa mengubah status dari {$locked->status->getLabel()} ke {$to->getLabel()}.");
            }

            $previous = $locked->status;

            if ($guard !== null) {
                $guard($locked);
            }

            $locked->status = $to;
            $locked->save();

            $election->setRawAttributes($locked->getAttributes(), true);
        });

        $this->audit->log('election.status_changed', $election, $election, note: $note, meta: [
            'from' => $previous?->value,
            'to' => $to->value,
            ...($meta === null ? [] : $meta($election)),
        ], actor: $actor);

        $this->waves->publishStatus($election);
    }
}
