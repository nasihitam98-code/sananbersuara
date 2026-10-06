<?php

namespace App\Services\Results;

use App\Enums\BallotScope;
use App\Enums\ElectionStatus;
use App\Enums\OutcomeStatus;
use App\Enums\ReportStatus;
use App\Models\Ballot;
use App\Models\Election;
use App\Models\Outcome;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Voting\RoundResolver;
use App\Services\Voting\VotingException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penetapan hasil oleh Super Admin (K21) dan syarat publikasi (K08).
 */
class ResultPublication
{
    public function __construct(
        private ResultSlots $slots,
        private OfficialReportService $reports,
        private AuditLogger $audit,
    ) {}

    public function outcome(Ballot $ballot, ?Unit $unit): ?Outcome
    {
        return Outcome::query()
            ->where('ballot_id', $ballot->id)
            ->where('unit_id', $unit?->id)
            ->with('candidates')
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<int, int>  $candidateIds
     */
    public function decide(Election $election, Ballot $ballot, ?Unit $unit, OutcomeStatus $status, array $candidateIds, ?string $note, User $actor): Outcome
    {
        if (! in_array($election->status, [ElectionStatus::Verifikasi, ElectionStatus::Unpublished], true)) {
            throw VotingException::invalidState('Penetapan hanya bisa dilakukan saat status Verifikasi.');
        }

        if ($ballot->election_id !== $election->id || ($ballot->scope === BallotScope::PerRt) !== ($unit !== null)) {
            throw VotingException::invalidChoice();
        }

        $allowedIds = $ballot->ballotCandidates()
            ->when($unit !== null, fn ($query) => $query->where('unit_id', $unit->id))
            ->pluck('id')
            ->all();

        $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));

        if (array_diff($candidateIds, $allowedIds) !== []) {
            throw VotingException::invalidChoice();
        }

        if ($status === OutcomeStatus::Ditetapkan && $candidateIds === []) {
            throw VotingException::invalidState('Pilih minimal satu calon yang ditetapkan.');
        }

        if ($status === OutcomeStatus::BelumDitetapkan) {
            $candidateIds = [];

            if (blank($note)) {
                throw VotingException::invalidState('Jelaskan alasan belum ditetapkan (mis. seri, menunggu putaran 2).');
            }
        }

        $outcome = DB::transaction(function () use ($election, $ballot, $unit, $status, $candidateIds, $note, $actor): Outcome {
            $outcome = $this->outcome($ballot, $unit) ?? new Outcome;
            $outcome->forceFill([
                'election_id' => $election->id,
                'ballot_id' => $ballot->id,
                'unit_id' => $unit?->id,
                'round_id' => (app(RoundResolver::class)->roundFor($election, $ballot, $unit?->id) ?? $election->currentRound())->id,
                'status' => $status,
                'note' => $note,
                'decided_by' => $actor->id,
                'decided_at' => Carbon::now(),
            ])->save();

            $outcome->candidates()->sync($candidateIds);

            return $outcome->load('candidates');
        });

        $this->reports->invalidate($election, $ballot->scope === BallotScope::PerRt ? $unit : null, $actor);

        $this->audit->log('result.outcome_decided', $outcome, $election, note: $note, meta: [
            'ballot' => $ballot->title,
            'unit' => $unit?->name,
            'status' => $status->value,
            'candidates' => $outcome->candidates->map(fn ($candidate): string => $candidate->displayNumber().' '.$candidate->name)->all(),
        ], actor: $actor);

        return $outcome;
    }

    /**
     * Hal yang masih menghalangi publikasi. Kosong berarti boleh dipublikasikan.
     *
     * @return array<int, string>
     */
    public function publishProblems(Election $election): array
    {
        $problems = [];

        foreach ($this->slots->slots($election) as $slot) {
            if ($this->outcome($slot['ballot'], $slot['unit']) === null) {
                $label = $slot['ballot']->title.($slot['unit'] !== null ? ' '.$slot['unit']->name : '');
                $problems[] = "Penetapan \"{$label}\" belum diisi.";
            }
        }

        foreach ($this->slots->reportScopes($election) as $scope) {
            if ($this->reports->current($election, $scope)?->status !== ReportStatus::Disahkan) {
                $problems[] = 'Berita acara '.($scope?->name ?? 'Keseluruhan').' belum disahkan.';
            }
        }

        return $problems;
    }
}
