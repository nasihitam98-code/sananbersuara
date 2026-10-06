<?php

namespace App\Services\Results;

use App\Enums\ElectionStatus;
use App\Enums\VoteStatus;
use App\Models\Election;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Voting\VotingException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Retensi data pribadi (K26, UU PDP):
 * 1. Keterkaitan pemilih–pilihan (Mode Resmi) dihapus N hari setelah dipublikasikan (default 30, bisa diperpanjang).
 *    Status "sudah memilih" disimpan tanpa pilihan. Setelah ini Detail Suara tidak tersedia lagi.
 * 2. Satu tahun setelah ditutup: snapshot hak pilih, log izin, pengajuan koreksi, dan nama peserta
 *    Mode Dadakan dianonimkan. Angka di berita acara (snapshot terkunci) tetap utuh.
 */
class DataRetention
{
    public function __construct(private AuditLogger $audit) {}

    public function linkageDeadline(Election $election): ?Carbon
    {
        if ($election->isDadakan() || $election->published_at === null) {
            return null;
        }

        return $election->published_at->copy()->addDays((int) $election->setting('linkage_retention_days'));
    }

    public function extendLinkage(Election $election, int $extraDays, string $reason, User $actor): void
    {
        abort_unless($actor->isSuperAdmin(), 403);

        if ($election->vote_links_destroyed_at !== null) {
            throw VotingException::invalidState('Keterkaitan suara sudah dihapus dan tidak bisa dipulihkan.');
        }

        $settings = $election->settings ?? [];
        $before = (int) $election->setting('linkage_retention_days');
        $settings['linkage_retention_days'] = $before + $extraDays;
        $election->settings = $settings;
        $election->save();

        $this->audit->log('retention.linkage_extended', $election, $election, note: $reason, meta: [
            'from_days' => $before,
            'to_days' => $settings['linkage_retention_days'],
        ], actor: $actor);
    }

    /**
     * Menghapus keterkaitan pemilih–pilihan secara permanen.
     */
    public function purgeLinkage(Election $election): void
    {
        if ($election->isDadakan() || $election->vote_links_destroyed_at !== null) {
            return;
        }

        DB::transaction(function () use ($election): void {
            $ballotIds = $election->ballots()->pluck('id');

            // Simpan status "sudah memilih" (tanpa pilihan) sebelum ID pemilih dihapus dari suara.
            DB::table('ballot_voters')
                ->whereIn('ballot_id', $ballotIds)
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('votes')
                    ->whereColumn('votes.ballot_id', 'ballot_voters.ballot_id')
                    ->whereColumn('votes.voter_id', 'ballot_voters.voter_id')
                    ->where('votes.status', VoteStatus::Sah->value))
                ->update(['voted_at' => Carbon::now()]);

            DB::table('votes')->where('election_id', $election->id)->update(['voter_id' => null, 'permit_id' => null]);
            DB::table('vote_corrections')->where('election_id', $election->id)->update(['vote_id' => null]);

            $election->forceFill(['vote_links_destroyed_at' => Carbon::now()])->save();
        });

        $this->audit->log('retention.linkage_purged', $election, $election, actorType: 'system');
    }

    /**
     * Anonimisasi data pribadi per pemilihan setelah satu tahun.
     */
    public function purgePersonalData(Election $election): void
    {
        if ($election->personal_data_purged_at !== null) {
            return;
        }

        $this->purgeLinkage($election);

        DB::transaction(function () use ($election): void {
            $ballotIds = $election->ballots()->pluck('id');

            DB::table('vote_corrections')->where('election_id', $election->id)->delete();
            DB::table('permits')->where('election_id', $election->id)->delete();
            DB::table('ballot_voters')->whereIn('ballot_id', $ballotIds)->delete();

            DB::table('attendees')->where('election_id', $election->id)->update([
                'name' => DB::raw("concat('Peserta ', seq_no)"),
                'name_search' => DB::raw("concat('peserta ', seq_no)"),
                'pin_hash' => str_repeat('0', 64),
                'unit_id' => null,
            ]);

            $election->forceFill(['personal_data_purged_at' => Carbon::now()])->save();
        });

        $this->audit->log('retention.personal_data_purged', $election, $election, actorType: 'system');
    }

    /**
     * Dijalankan terjadwal tiap hari.
     *
     * @return array{linkage: int, personal: int}
     */
    public function run(): array
    {
        $linkage = 0;
        $personal = 0;

        $finished = Election::query()->whereIn('status', [ElectionStatus::Published, ElectionStatus::Archived, ElectionStatus::Cancelled, ElectionStatus::Unpublished, ElectionStatus::Verifikasi, ElectionStatus::Ditutup])->get();

        foreach ($finished as $election) {
            $deadline = $this->linkageDeadline($election);

            if ($election->status === ElectionStatus::Published && $deadline !== null && $deadline->isPast() && $election->vote_links_destroyed_at === null) {
                $this->purgeLinkage($election);
                $linkage++;
            }

            if ($election->closed_at !== null && $election->closed_at->copy()->addYear()->isPast() && $election->personal_data_purged_at === null) {
                $this->purgePersonalData($election);
                $personal++;
            }
        }

        return ['linkage' => $linkage, 'personal' => $personal];
    }
}
