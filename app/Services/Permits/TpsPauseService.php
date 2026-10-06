<?php

namespace App\Services\Permits;

use App\Enums\TpsPauseReason;
use App\Models\Election;
use App\Models\TpsPause;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InternalNotifier;
use App\Services\Voting\VotingException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Jeda per TPS RT (K15): izin baru di RT itu ditolak, pemilih yang sudah di bilik boleh menyelesaikan.
 * Boleh oleh Super Admin (semua RT) atau Petugas Meja RT itu dari laptop Meja-nya (dicek pemanggil).
 */
class TpsPauseService
{
    public function __construct(
        private AuditLogger $audit,
        private InternalNotifier $notifier,
    ) {}

    public function active(Election $election, int $unitId): ?TpsPause
    {
        return TpsPause::query()
            ->where('election_id', $election->id)
            ->where('unit_id', $unitId)
            ->whereNull('resumed_at')
            ->first();
    }

    public function pause(Election $election, Unit $unit, TpsPauseReason $reason, ?string $note, User $actor): TpsPause
    {
        if (! $election->status->isLive()) {
            throw VotingException::invalidState('TPS hanya bisa dijeda saat pemilihan berlangsung.');
        }

        try {
            $pause = new TpsPause;
            $pause->forceFill([
                'election_id' => $election->id,
                'unit_id' => $unit->id,
                'reason_code' => $reason,
                'note' => $note,
                'paused_at' => Carbon::now(),
                'paused_by' => $actor->id,
                'active_key' => 1,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw VotingException::invalidState("TPS {$unit->name} sudah dalam keadaan dijeda.");
        }

        $this->audit->log('tps.paused', $unit, $election, reasonCode: $reason->value, note: $note, actor: $actor);
        $this->notifier->notifySuperAdmins('TPS dijeda', "TPS {$unit->name} dijeda oleh {$actor->name}: {$reason->getLabel()}.", $actor);

        return $pause;
    }

    public function resume(Election $election, Unit $unit, User $actor): void
    {
        $pause = $this->active($election, $unit->id);

        if ($pause === null) {
            throw VotingException::invalidState("TPS {$unit->name} tidak sedang dijeda.");
        }

        $pause->forceFill(['resumed_at' => Carbon::now(), 'resumed_by' => $actor->id, 'active_key' => null])->save();

        $this->audit->log('tps.resumed', $unit, $election, meta: ['paused_minutes' => (int) $pause->paused_at->diffInMinutes(Carbon::now())], actor: $actor);
    }
}
