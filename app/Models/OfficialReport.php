<?php

namespace App\Models;

use App\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Berita acara. Isi (content) adalah snapshot JSON; checksum = sha256 dari content.
 * Dibuat dan diubah hanya oleh OfficialReportService.
 */
#[RouteKey('public_id')]
class OfficialReport extends Model
{
    use HasUlids;

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
            'status' => ReportStatus::class,
            'ratified_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return json_decode($this->content, true);
    }

    public function checksumIsValid(): bool
    {
        return hash_equals($this->checksum, hash('sha256', $this->content));
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function ratifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ratified_by');
    }

    public function scopeLabel(): string
    {
        return $this->unit?->name ?? 'Keseluruhan';
    }
}
