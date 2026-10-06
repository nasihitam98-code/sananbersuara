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

            if ($election->isDadakan()) {
                DB::table('votes')->where('election_id', $election->id)->update(['voter_link' => null]);
                $election->vote_links_destroyed_at = $now;
            } else {
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

    public function cancel(Election $election, User $actor, string $note): void
    {
        $allowed = [ElectionStatus::Draft, ElectionStatus::Ready, ElectionStatus::Berlangsung, ElectionStatus::Paused, ElectionStatus::Ditutup, ElectionStatus::Verifikasi];

        $this->transition($election, $allowed, ElectionStatus::Cancelled, $actor, function (Election $election): void {
            if ($election->isDadakan()) {
                DB::table('votes')->where('election_id', $election->id)->update(['voter_link' => null]);
                $election->vote_links_destroyed_at = Carbon::now();
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
