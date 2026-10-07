<?php

namespace App\Services\Voting;

use App\Enums\ElectionStatus;
use App\Enums\WaveKind;
use App\Enums\WaveStatus;
use App\Models\Election;
use App\Models\User;
use App\Models\Wave;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Buka, perpanjang, jeda, dan tutup gelombang (Mode Dadakan).
 */
class WaveManager
{
    public function __construct(
        private AuditLogger $audit,
        private StatusPublisher $publisher,
    ) {}

    /**
     * @param  int|null  $minutes  Kosong = tanpa timer; ditutup manual dengan close().
     * @param  string|null  $name  Nama sesi bebas, mis. "Sesi lansia". Kosong = "Gelombang N".
     */
    public function open(Election $election, WaveKind $kind, ?int $minutes, User $actor, ?string $name = null): Wave
    {
        $name = filled($name) ? Str::of($name)->squish()->limit(60, '')->toString() : null;

        $wave = DB::transaction(function () use ($election, $kind, $minutes, $actor, $name): Wave {
            $election = Election::query()->whereKey($election->id)->lockForUpdate()->firstOrFail();

            if ($election->status !== ElectionStatus::Berlangsung) {
                throw VotingException::invalidState('Gelombang hanya bisa dibuka saat pemilihan Berlangsung.');
            }

            $round = $election->currentRound();

            if ($round === null) {
                throw VotingException::invalidState('Putaran belum dibuka.');
            }

            $this->finalizeExpired($election);

            if ($election->openWave() !== null) {
                throw VotingException::invalidState('Masih ada gelombang yang terbuka. Tutup dulu.');
            }

            $wave = new Wave;
            $wave->round()->associate($round);
            $wave->number = (int) $round->waves()->max('number') + 1;
            $wave->name = $name;
            $wave->kind = $kind;
            $wave->status = WaveStatus::Dibuka;
            $wave->opened_at = Carbon::now();
            $wave->ends_at = $minutes === null ? null : Carbon::now()->addMinutes($minutes);
            $wave->opened_by = $actor->id;
            $wave->save();

            return $wave;
        });

        $this->audit->log('wave.opened', $wave, $election, meta: [
            'number' => $wave->number,
            'name' => $wave->name,
            'kind' => $kind->value,
            'minutes' => $minutes,
        ], actor: $actor);

        $this->publishStatus($election);

        return $wave;
    }

    public function extend(Election $election, int $minutes, User $actor): Wave
    {
        $wave = DB::transaction(function () use ($election, $minutes): Wave {
            $wave = $this->lockOpenWave($election);

            if ($wave->status === WaveStatus::Dijeda) {
                $wave->paused_remaining_seconds += $minutes * 60;
            } else {
                $base = $wave->ends_at === null || $wave->ends_at->isPast() ? Carbon::now() : $wave->ends_at;
                $wave->ends_at = $base->copy()->addMinutes($minutes);
            }

            $wave->save();

            return $wave;
        });

        $this->audit->log('wave.extended', $wave, $election, meta: ['number' => $wave->number, 'name' => $wave->name, 'minutes' => $minutes], actor: $actor);
        $this->publishStatus($election);

        return $wave;
    }

    public function close(Election $election, User $actor): Wave
    {
        $wave = DB::transaction(function () use ($election): Wave {
            $wave = $this->lockOpenWave($election);
            $wave->status = WaveStatus::Ditutup;
            $wave->closed_at = Carbon::now();
            $wave->paused_remaining_seconds = null;
            $wave->save();

            return $wave;
        });

        $this->audit->log('wave.closed', $wave, $election, meta: ['number' => $wave->number, 'name' => $wave->name, 'early' => true], actor: $actor);
        $this->publishStatus($election);

        return $wave;
    }

    /**
     * Dipanggil saat pemilihan dijeda: timer berhenti dan sisa waktu disimpan.
     */
    public function pause(Election $election): void
    {
        $wave = $election->openWave();

        if ($wave === null || $wave->status !== WaveStatus::Dibuka) {
            return;
        }

        // Sisa waktu dihitung sebelum status berubah: remainingSeconds() membaca kolom jeda saat status DIJEDA.
        $wave->paused_remaining_seconds = $wave->ends_at === null ? null : $wave->remainingSeconds();
        $wave->status = WaveStatus::Dijeda;
        $wave->save();

        $this->publishStatus($election);
    }

    public function resume(Election $election): void
    {
        $wave = $election->openWave();

        if ($wave === null || $wave->status !== WaveStatus::Dijeda) {
            return;
        }

        $wave->status = WaveStatus::Dibuka;
        $wave->ends_at = $wave->paused_remaining_seconds === null ? null : Carbon::now()->addSeconds($wave->paused_remaining_seconds);
        $wave->paused_remaining_seconds = null;
        $wave->save();

        $this->publishStatus($election);
    }

    /**
     * Gelombang yang timernya sudah lewat ditandai ditutup, dengan waktu tutup = batas timer.
     */
    public function finalizeExpired(Election $election): void
    {
        $wave = $election->openWave();

        if ($wave === null || $wave->status !== WaveStatus::Dibuka || $wave->ends_at === null || $wave->ends_at->isFuture()) {
            return;
        }

        $wave->status = WaveStatus::Ditutup;
        $wave->closed_at = $wave->ends_at;
        $wave->save();

        $this->audit->log('wave.closed', $wave, $election, meta: ['number' => $wave->number, 'name' => $wave->name, 'early' => false], actorType: 'system');
        $this->publishStatus($election);
    }

    public function publishStatus(Election $election): void
    {
        $this->publisher->publish($election);
    }

    private function lockOpenWave(Election $election): Wave
    {
        $openWave = $election->openWave();

        if ($openWave === null) {
            throw VotingException::invalidState('Tidak ada gelombang yang terbuka.');
        }

        return Wave::query()->whereKey($openWave->id)->lockForUpdate()->firstOrFail();
    }
}
