<?php

namespace App\Services\Results;

use App\Enums\BallotScope;
use App\Enums\ElectionStatus;
use App\Enums\OutcomeStatus;
use App\Enums\ReportStatus;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Outcome;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Voting\ResultsCalculator;
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
            'candidates' => $outcome->candidates->map(fn ($candidate): string => $candidate->displayNumber().' '.$candidate->nameWithOrigin())->all(),
        ], actor: $actor);

        return $outcome;
    }

    /**
     * Calon dengan suara terbanyak di satu surat suara/RT, atau null bila seri di urutan teratas atau belum ada suara.
     */
    public function topVoted(Election $election, Ballot $ballot, ?Unit $unit): ?Candidate
    {
        $round = app(RoundResolver::class)->roundFor($election, $ballot, $unit?->id) ?? $election->currentRound();
        $leaders = collect(app(ResultsCalculator::class)->tally($ballot, $round, $unit)['candidates'])
            ->filter(fn (array $row): bool => $row['rank'] === 1 && $row['votes'] > 0);

        return $leaders->count() === 1 ? $leaders->first()['candidate'] : null;
    }

    /**
     * Tetapkan sekaligus: setiap surat suara/RT yang belum ditetapkan diisi calon dengan suara terbanyak. Yang sudah
     * ditetapkan tidak diubah; yang seri atau belum ada suara dilewati untuk ditetapkan manual.
     *
     * @return array{decided: int, skipped: array<int, string>}
     */
    public function decideTopVoted(Election $election, User $actor): array
    {
        $decided = 0;
        $skipped = [];

        foreach ($this->slots->slots($election) as $slot) {
            if ($this->outcome($slot['ballot'], $slot['unit']) !== null) {
                continue;
            }

            $winner = $this->topVoted($election, $slot['ballot'], $slot['unit']);

            if ($winner === null) {
                $skipped[] = $slot['ballot']->title.($slot['unit'] !== null ? ' '.$slot['unit']->name : '');

                continue;
            }

            $this->decide($election, $slot['ballot'], $slot['unit'], OutcomeStatus::Ditetapkan, [$winner->id], null, $actor);
            $decided++;
        }

        return ['decided' => $decided, 'skipped' => $skipped];
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
