<?php

namespace App\Models;

use App\Enums\WaveKind;
use App\Enums\WaveStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Gelombang dibuat dan diubah hanya oleh WaveManager (tanpa mass assignment).
 */
class Wave extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => WaveKind::class,
            'status' => WaveStatus::class,
            'opened_at' => 'datetime',
            'ends_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Round, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function hasTimer(): bool
    {
        return $this->ends_at !== null || $this->paused_remaining_seconds !== null;
    }

    /**
     * Gelombang terbuka dan waktunya belum habis (tanpa masa tenggang).
     */
    public function isOpenAt(?CarbonInterface $moment = null): bool
    {
        $moment ??= Carbon::now();

        if ($this->status !== WaveStatus::Dibuka) {
            return false;
        }

        return $this->ends_at === null || $moment->lessThan($this->ends_at);
    }

    /**
     * Suara masih diterima: gelombang terbuka, atau waktunya habis kurang dari masa tenggang
     * (untuk pemilih yang sudah menekan konfirmasi sebelum batas tetapi jaringannya lambat).
     */
    public function acceptsSubmissionAt(int $graceSeconds, ?CarbonInterface $moment = null): bool
    {
        $moment ??= Carbon::now();

        if ($this->status === WaveStatus::Dijeda) {
            return false;
        }

        if ($this->status === WaveStatus::Dibuka && $this->ends_at === null) {
            return true;
        }

        $deadline = $this->status === WaveStatus::Ditutup ? $this->closed_at : $this->ends_at;

        return $deadline !== null && $moment->lessThanOrEqualTo($deadline->copy()->addSeconds($graceSeconds));
    }

    public function remainingSeconds(?CarbonInterface $moment = null): ?int
    {
        if ($this->status === WaveStatus::Dijeda) {
            return $this->paused_remaining_seconds;
        }

        if ($this->ends_at === null) {
            return null;
        }

        return max(0, (int) ceil(($moment ?? Carbon::now())->diffInSeconds($this->ends_at, false)));
    }
}
