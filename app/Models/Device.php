<?php

namespace App\Models;

use App\Enums\DeviceKind;
use App\Enums\DeviceReleaseReason;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Slot perangkat (Meja/Bilik). Diubah hanya oleh DeviceManager.
 */
#[Hidden(['pairing_token_hash', 'session_secret_hash'])]
#[RouteKey('public_id')]
class Device extends Model
{
    use HasUlids;

    /** Detik tanpa heartbeat sebelum perangkat dianggap terputus (K05). */
    public const DISCONNECT_AFTER_SECONDS = 30;

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
            'kind' => DeviceKind::class,
            'release_reason' => DeviceReleaseReason::class,
            'pairing_token_expires_at' => 'datetime',
            'paired_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'released_at' => 'datetime',
            'last_permit_ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Election, $this>
     */
    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return HasOne<Permit, $this>
     */
    public function activePermit(): HasOne
    {
        return $this->hasOne(Permit::class, 'active_device_id');
    }

    public function isPaired(): bool
    {
        return $this->session_secret_hash !== null;
    }

    public function isOnline(): bool
    {
        return $this->isPaired()
            && $this->last_seen_at !== null
            && $this->last_seen_at->greaterThan(Carbon::now()->subSeconds(self::DISCONNECT_AFTER_SECONDS));
    }

    public function name(): string
    {
        return $this->kind === DeviceKind::Meja
            ? 'Meja '.$this->unit->name
            : 'Bilik '.$this->number;
    }

    public function code(): string
    {
        return 'RT'.$this->unit->code.'-'.($this->kind === DeviceKind::Meja ? 'MEJA' : str_pad((string) $this->number, 2, '0', STR_PAD_LEFT));
    }

    /**
     * Status untuk halaman Perangkat Terhubung (bagian 5.4).
     */
    public function connectionState(): string
    {
        if (! $this->isPaired()) {
            return $this->released_at !== null ? 'Dilepas' : 'Belum tersambung';
        }

        if (! $this->isOnline()) {
            return 'Terputus';
        }

        if ($this->kind === DeviceKind::Bilik && $this->relationLoaded('activePermit') && $this->activePermit !== null) {
            return $this->activePermit->status->value === 'DIPAKAI' ? 'Sedang dipakai' : 'Dipesan';
        }

        return 'Menunggu';
    }
}
