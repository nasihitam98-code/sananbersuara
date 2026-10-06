<?php

namespace App\Services\Voting;

use App\Enums\BallotScope;
use App\Enums\ElectionStatus;
use App\Enums\RoundStatus;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Round;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Devices\DeviceManager;
use App\Services\Results\OfficialReportService;
use App\Services\Results\ResultSlots;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Putaran berikutnya (K22): dibuka Super Admin sebelum hasil dipublikasikan, untuk surat suara/RT
 * dan calon yang ditetapkan panitia. Pemilihan kembali BERLANGSUNG; pemilih yang berhak memilih lagi
 * hanya pada cakupan putaran itu. Sistem tidak memilih calon putaran berikutnya secara otomatis.
 */
class NextRoundService
{
    public function __construct(
        private ElectionLifecycle $lifecycle,
        private ResultSlots $slots,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<int, string>  $slotKeys  kunci slot "ballotId:unitId"
     * @param  array<int, int>  $candidateIds
     */
    public function open(Election $election, array $slotKeys, array $candidateIds, string $reason, User $actor): Round
    {
        abort_unless($actor->isSuperAdmin(), 403);

        if (! in_array($election->status, [ElectionStatus::Ditutup, ElectionStatus::Verifikasi], true)) {
            throw VotingException::invalidState('Putaran berikutnya hanya bisa dibuka setelah ditutup dan sebelum hasil dipublikasikan.');
        }

        $available = $this->slots->slots($election)->keyBy('key');
        $chosen = collect($slotKeys)->unique()->map(fn (string $key): ?array => $available->get($key));

        if ($chosen->isEmpty() || $chosen->contains(null)) {
            throw VotingException::invalidState('Pilih surat suara/RT yang akan diulang.');
        }

        $candidates = Candidate::query()->whereIn('id', $candidateIds)->get();

        foreach ($chosen as $slot) {
            $inSlot = $candidates->filter(fn (Candidate $candidate): bool => $candidate->ballot_id === $slot['ballot']->id
                && ($slot['unit'] === null || $candidate->unit_id === $slot['unit']->id));

            if ($inSlot->count() < 2) {
                $label = $slot['ballot']->title.($slot['unit'] !== null ? ' '.$slot['unit']->name : '');
                throw VotingException::invalidState("Pilih minimal dua calon untuk \"{$label}\".");
            }
        }

        $belongs = $candidates->every(fn (Candidate $candidate): bool => $chosen->contains(fn (array $slot): bool => $candidate->ballot_id === $slot['ballot']->id
            && ($slot['unit'] === null || $candidate->unit_id === $slot['unit']->id)));

        if (! $belongs || $candidates->count() !== count(array_unique($candidateIds))) {
            throw VotingException::invalidChoice();
        }

        $round = null;

        $this->lifecycle->startNextRound($election, $actor, function (Election $election) use ($chosen, $candidates, &$round): void {
            $now = Carbon::now();

            $round = new Round;
            $round->election()->associate($election);
            $round->number = (int) $election->rounds()->max('number') + 1;
            $round->status = RoundStatus::Dibuka;
            $round->opened_at = $now;
            $round->save();

            DB::table('round_scopes')->insert($chosen->map(fn (array $slot): array => [
                'round_id' => $round->id,
                'ballot_id' => $slot['ballot']->id,
                'unit_id' => $slot['unit']?->id,
            ])->values()->all());

            DB::table('round_candidates')->insert($candidates->map(fn (Candidate $candidate): array => [
                'round_id' => $round->id,
                'candidate_id' => $candidate->id,
            ])->values()->all());
        }, $reason);

        $reports = app(OfficialReportService::class);

        foreach ($chosen as $slot) {
            $reports->invalidate($election, $slot['ballot']->scope === BallotScope::PerRt ? $slot['unit'] : null, $actor);
        }

        if (! $election->isDadakan()) {
            app(DeviceManager::class)->ensureSlots($election);
        }

        $this->audit->log('round.opened', $round, $election, note: $reason, meta: [
            'number' => $round->number,
            'slots' => $chosen->map(fn (array $slot): string => $slot['ballot']->title.($slot['unit'] instanceof Unit ? ' '.$slot['unit']->name : ''))->values()->all(),
            'candidates' => $candidates->map(fn (Candidate $candidate): string => $candidate->displayNumber().' '.$candidate->name)->values()->all(),
        ], actor: $actor);

        return $round;
    }
}
