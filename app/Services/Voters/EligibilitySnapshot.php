<?php

namespace App\Services\Voters;

use App\Enums\BallotScope;
use App\Models\Ballot;
use App\Models\BallotVoter;
use App\Models\Election;
use App\Models\Voter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Snapshot hak pilih per surat suara (bagian 5A), dibekukan saat pemilihan Mode Resmi dimulai.
 *
 * - PER_RT      : pemilih aktif dari RT yang punya calon di surat suara itu
 * - RT_TERTENTU : pemilih aktif dari RT yang dipilih Super Admin
 * - SEMUA_RT    : semua pemilih aktif
 */
class EligibilitySnapshot
{
    /**
     * RT yang berhak memilih pada surat suara ini.
     *
     * @return Collection<int, int>
     */
    public function eligibleUnitIds(Ballot $ballot): Collection
    {
        return match ($ballot->scope) {
            BallotScope::PerRt => $ballot->candidates()->reorder()->whereNotNull('unit_id')->distinct()->pluck('unit_id'),
            BallotScope::RtTertentu => $ballot->units()->pluck('units.id'),
            BallotScope::SemuaRt => DB::table('units')->pluck('id'),
            BallotScope::DaftarHadir => collect(),
        };
    }

    /**
     * @return array<string, int> jumlah pemilih berhak per surat suara (judul => jumlah)
     */
    public function freeze(Election $election): array
    {
        $counts = [];
        $now = Carbon::now();

        foreach ($election->ballots()->get() as $ballot) {
            $unitIds = $this->eligibleUnitIds($ballot);

            Voter::query()
                ->where('is_active', true)
                ->whereIn('unit_id', $unitIds)
                ->select(['id', 'unit_id'])
                ->chunkById(500, function (Collection $voters) use ($ballot, $now): void {
                    DB::table('ballot_voters')->insertOrIgnore($voters->map(fn (Voter $voter): array => [
                        'ballot_id' => $ballot->id,
                        'voter_id' => $voter->id,
                        'unit_id' => $voter->unit_id,
                        'added_during_live' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all());
                });

            $counts[$ballot->title] = BallotVoter::query()->where('ballot_id', $ballot->id)->count();
        }

        return $counts;
    }

    /**
     * Pemilih yang ditambah lewat jalur darurat saat berlangsung langsung masuk snapshot surat suara yang sesuai.
     */
    public function addDuringLive(Election $election, Voter $voter, string $reason): void
    {
        foreach ($election->ballots()->get() as $ballot) {
            if (! $this->eligibleUnitIds($ballot)->contains($voter->unit_id)) {
                continue;
            }

            $entry = new BallotVoter;
            $entry->forceFill([
                'ballot_id' => $ballot->id,
                'voter_id' => $voter->id,
                'unit_id' => $voter->unit_id,
                'added_during_live' => true,
                'added_reason' => $reason,
            ])->save();
        }
    }

    public function isEligible(Ballot $ballot, Voter $voter): bool
    {
        return BallotVoter::query()
            ->where('ballot_id', $ballot->id)
            ->where('voter_id', $voter->id)
            ->whereNull('revoked_at')
            ->exists();
    }
}
