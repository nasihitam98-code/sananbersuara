<?php

namespace App\Services\Permits;

use App\Enums\BallotScope;
use App\Enums\ElectionStatus;
use App\Enums\PermitStatus;
use App\Enums\VoteStatus;
use App\Models\Ballot;
use App\Models\BallotVoter;
use App\Models\Candidate;
use App\Models\Device;
use App\Models\Permit;
use App\Models\Vote;
use App\Services\Voting\VotingException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Penyimpanan suara di bilik (Mode Resmi). Pemeriksaan di server pada saat simpan (bagian 5A):
 * izin aktif milik bilik ini, pemilih berhak atas surat suara, calon milik surat suara dan
 * (untuk surat suara per RT) milik RT pemilih menurut snapshot. Unique index menolak suara kedua.
 */
class BoothBallotBox
{
    public function __construct(private PermitManager $permits) {}

    /**
     * Izin aktif di bilik ini, atau null.
     */
    public function activePermit(Device $booth): ?Permit
    {
        return Permit::query()
            ->where('active_device_id', $booth->id)
            ->with('voter')
            ->first();
    }

    /**
     * @return array{finished: bool}
     */
    public function cast(Device $booth, Permit $permit, Ballot $ballot, Candidate $candidate): array
    {
        $finished = DB::transaction(function () use ($booth, $permit, $ballot, $candidate): bool {
            $locked = Permit::query()->whereKey($permit->id)->lockForUpdate()->firstOrFail();
            $election = $locked->election()->firstOrFail();

            if (! $locked->status->isActive() || $locked->device_id !== $booth->id) {
                throw VotingException::sessionExpired();
            }

            if (! in_array($election->status, [ElectionStatus::Berlangsung, ElectionStatus::Paused], true)) {
                throw VotingException::timeUp();
            }

            if ($ballot->election_id !== $election->id || $candidate->ballot_id !== $ballot->id) {
                throw VotingException::invalidChoice();
            }

            $entry = BallotVoter::query()
                ->where('ballot_id', $ballot->id)
                ->where('voter_id', $locked->voter_id)
                ->whereNull('revoked_at')
                ->first();

            if ($entry === null) {
                throw VotingException::invalidChoice();
            }

            if ($ballot->scope === BallotScope::PerRt && $candidate->unit_id !== $entry->unit_id) {
                throw VotingException::invalidChoice();
            }

            try {
                $vote = new Vote;
                $vote->forceFill([
                    'election_id' => $election->id,
                    'ballot_id' => $ballot->id,
                    'round_id' => $locked->round_id,
                    'candidate_id' => $candidate->id,
                    'voter_id' => $locked->voter_id,
                    'permit_id' => $locked->id,
                    'device_id' => $booth->id,
                    'status' => VoteStatus::Sah,
                    'active_key' => 1,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw VotingException::alreadyVoted();
            }

            $this->permits->touch($locked);

            if ($this->permits->pendingBallots($locked->voter, $election)->isEmpty()) {
                $this->permits->end($locked, PermitStatus::Selesai, null, null);

                return true;
            }

            return false;
        });

        return ['finished' => $finished];
    }
}
