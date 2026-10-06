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
use SensitiveParameter;

/**
 * Pemilih terdaftar (Mode Resmi). RT (unit_id) tidak bisa diisi lewat mass assignment;
 * hanya diset oleh alur yang sudah memeriksa hak akses (Admin RT = RT sendiri).
 */
#[Fillable(['name', 'address', 'gender', 'birth_date', 'phone'])]
#[Hidden(['nik_hash', 'phone'])]
#[RouteKey('public_id')]
class Voter extends Model
{
    use HasFactory, HasUlids;

    protected static function booted(): void
    {
        static::saving(function (Voter $voter): void {
            $voter->name = Attendee::cleanName($voter->name);
            $voter->name_search = Attendee::normalizeForSearch($voter->name);
            $voter->address = Str::of((string) $voter->address)->squish()->limit(190, '')->toString();
        });

        static::created(function (Voter $voter): void {
            $voter->forceFill(['voter_number' => 'VTR-'.str_pad((string) $voter->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();
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
            'birth_date' => 'date',
            'phone' => 'encrypted',
            'is_active' => 'boolean',
            'added_during_live' => 'boolean',
        ];
    }

    /**
     * HMAC berkunci (bukan hash biasa) agar NIK tidak bisa ditebak dari database yang bocor.
     */
    public static function hashNik(#[SensitiveParameter] string $nik): string
    {
        return hash_hmac('sha256', 'nik|'.$nik, (string) config('app.key'));
    }

    public static function normalizeNik(?string $nik): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $nik);

        return $digits === '' ? null : $digits;
    }

    public function setNik(#[SensitiveParameter] ?string $nik): void
    {
        $nik = static::normalizeNik($nik);

        $this->nik_hash = $nik === null ? null : static::hashNik($nik);
        $this->nik_last4 = $nik === null ? null : substr($nik, -4);
    }

    public function maskedNik(): string
    {
        return $this->nik_last4 === null ? '-' : '************'.$this->nik_last4;
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return HasMany<BallotVoter, $this>
     */
    public function ballotEntries(): HasMany
    {
        return $this->hasMany(BallotVoter::class);
    }

    /**
     * @return HasMany<Vote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    public function hasVotingHistory(): bool
    {
        return $this->votes()->exists();
    }
}
