<?php

namespace App\Services\Voting;

use App\Models\Attendee;
use App\Models\Election;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pendataan peserta di pintu (Mode Dadakan). PIN dikembalikan sekali untuk ditulis di kertas.
 */
class AttendeeRegistrar
{
    public function __construct(
        private PinService $pins,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{attendee: Attendee, pin: string}
     */
    public function register(Election $election, string $name, ?int $unitId, User $actor): array
    {
        if (! $election->isDadakan()) {
            throw VotingException::invalidState('Pendataan di pintu hanya untuk Mode Dadakan.');
        }

        if (! in_array($election->status->value, ['READY', 'BERLANGSUNG', 'PAUSED'], true)) {
            throw VotingException::invalidState('Pendataan hanya bisa saat pemilihan Siap atau Berlangsung.');
        }

        $pin = $this->pins->generate();

        $attendee = DB::transaction(function () use ($election, $name, $unitId, $actor, $pin): Attendee {
            Election::query()->whereKey($election->id)->lockForUpdate()->first();

            $attendee = new Attendee(['name' => $name, 'unit_id' => $unitId]);
            $attendee->election()->associate($election);
            $attendee->seq_no = (int) Attendee::query()->where('election_id', $election->id)->max('seq_no') + 1;
            $attendee->public_id = $attendee->newUniqueId();
            $attendee->pin_hash = $this->pins->hash($attendee->public_id, $pin);
            $attendee->pin_issued_at = Carbon::now();
            $attendee->is_late = $election->openWave() !== null || $election->rounds()->whereHas('waves')->exists();
            $attendee->created_by = $actor->id;
            $attendee->save();

            return $attendee;
        });

        $this->audit->log('attendee.registered', $attendee, $election, meta: [
            'seq_no' => $attendee->seq_no,
            'is_late' => $attendee->is_late,
        ], actor: $actor);

        return ['attendee' => $attendee, 'pin' => $pin];
    }

    /**
     * Membuat PIN baru (PIN lama hangus) dan membuka kunci. Dipanggil oleh VoterRightRestorer.
     */
    public function reissuePin(Attendee $attendee): string
    {
        $pin = $this->pins->generate();

        $attendee->forceFill([
            'pin_hash' => $this->pins->hash($attendee->public_id, $pin),
            'pin_failed_attempts' => 0,
            'pin_locked_at' => null,
            'pin_issued_at' => Carbon::now(),
        ])->save();

        return $pin;
    }
}
