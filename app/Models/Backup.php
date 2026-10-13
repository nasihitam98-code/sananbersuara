<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan satu file backup. Dibuat dan diubah hanya oleh BackupService.
 */
#[RouteKey('public_id')]
class Backup extends Model
{
    use HasUlids;

    public const STATUS_RUNNING = 'BERJALAN';

    public const STATUS_SUCCESS = 'BERHASIL';

    public const STATUS_FAILED = 'GAGAL';

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'offsite_copied' => 'boolean',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;

        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024, 1).' KB';
    }

    public function triggerLabel(): string
    {
        return match ($this->trigger) {
            'MANUAL' => 'Manual (dari panel)',
            'MANUAL_KONSOL' => 'Manual (dari server)',
            'SEBELUM_RESTORE' => 'Pengaman sebelum restore',
            'TERJADWAL' => 'Otomatis (terjadwal)',
            default => (string) $this->trigger,
        };
    }

    /**
     * "Berjalan" yang sudah lama = terhenti (mis. proses mati, atau baris ikut terekam saat database di-backup).
     */
    public function isStalled(): bool
    {
        return $this->status === self::STATUS_RUNNING && $this->created_at?->lt(now()->subMinutes(30));
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->status === self::STATUS_SUCCESS => 'Berhasil',
            $this->status === self::STATUS_FAILED => 'Gagal',
            $this->isStalled() => 'Terhenti',
            default => 'Sedang berjalan',
        };
    }

    public function statusColor(): string
    {
        return match (true) {
            $this->status === self::STATUS_SUCCESS => 'success',
            $this->status === self::STATUS_FAILED, $this->isStalled() => 'danger',
            default => 'warning',
        };
    }
}
