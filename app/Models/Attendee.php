<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Peserta hadir Mode Dadakan. PIN hanya disimpan sebagai hash berpepper (lihat PinService).
 */
#[Fillable(['name', 'unit_id'])]
#[Hidden(['pin_hash'])]
#[RouteKey('public_id')]
class Attendee extends Model
{
    use HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::saving(function (Attendee $attendee): void {
            $attendee->name = static::cleanName($attendee->name);
            $attendee->name_search = static::normalizeForSearch($attendee->name);
        });
    }

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
            'pin_locked_at' => 'datetime',
            'pin_issued_at' => 'datetime',
            'is_late' => 'boolean',
        ];
    }

    public static function cleanName(string $name): string
    {
        return Str::of($name)->squish()->limit(120, '')->toString();
    }

    public static function normalizeForSearch(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9 ]/', '')->squish()->toString();
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
     * @return HasMany<AttendeeParticipation, $this>
     */
    public function participations(): HasMany
    {
        return $this->hasMany(AttendeeParticipation::class);
    }

    public function isPinLocked(): bool
    {
        return $this->pin_locked_at !== null;
    }

    public function displayNumber(): string
    {
        return str_pad((string) $this->seq_no, 3, '0', STR_PAD_LEFT);
    }
}
