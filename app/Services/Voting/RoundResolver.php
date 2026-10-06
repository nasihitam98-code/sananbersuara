<?php

namespace App\Services\Voting;

use App\Models\Ballot;
use App\Models\Election;
use App\Models\Round;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Menentukan putaran yang berlaku untuk tiap surat suara/RT (K22).
 * Putaran 1 mencakup semuanya; putaran berikutnya hanya cakupan yang tercatat di round_scopes.
 */
class RoundResolver
{
    public function isUnrestricted(Round $round): bool
    {
        return ! DB::table('round_scopes')->where('round_id', $round->id)->exists();
    }

    /**
     * Apakah putaran ini mencakup surat suara (dan RT, untuk surat suara per RT).
     */
    public function covers(Round $round, int $ballotId, ?int $unitId): bool
    {
        if ($this->isUnrestricted($round)) {
            return true;
        }

        return DB::table('round_scopes')
            ->where('round_id', $round->id)
            ->where('ballot_id', $ballotId)
            ->where(fn ($query) => $query->whereNull('unit_id')->when($unitId !== null, fn ($inner) => $inner->orWhere('unit_id', $unitId)))
            ->exists();
    }

    /**
     * Putaran terakhir yang mencakup slot hasil ini (untuk tally, berita acara, rekap).
     */
    public function roundFor(Election $election, Ballot $ballot, ?int $unitId): ?Round
    {
        return $election->rounds()->get()
            ->sortByDesc('number')
            ->first(fn (Round $round): bool => $this->covers($round, $ballot->id, $unitId));
    }

    /**
     * Surat suara yang dibuka di putaran ini (untuk pemilih dari RT tertentu, bila diberikan).
     *
     * @return Collection<int, int>
     */
    public function ballotIds(Election $election, Round $round, ?int $unitId = null): Collection
    {
        $all = $election->ballots()->pluck('id');

        if ($this->isUnrestricted($round)) {
            return $all;
        }

        return $all->filter(fn (int $ballotId): bool => $this->covers($round, $ballotId, $unitId))->values();
    }

    /**
     * Calon yang boleh dipilih di putaran ini, atau null jika tidak dibatasi (putaran 1).
     *
     * @return Collection<int, int>|null
     */
    public function allowedCandidateIds(Round $round): ?Collection
    {
        $ids = DB::table('round_candidates')->where('round_id', $round->id)->pluck('candidate_id');

        return $ids->isEmpty() ? null : $ids;
    }

    public function allowsCandidate(Round $round, int $candidateId): bool
    {
        $allowed = $this->allowedCandidateIds($round);

        return $allowed === null || $allowed->contains($candidateId);
    }
}
