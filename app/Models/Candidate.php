<?php

namespace App\Models;

use App\Enums\CandidateStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['unit_id', 'number', 'name', 'status'])]
#[RouteKey('public_id')]
class Candidate extends Model
{
    use HasFactory, HasUlids;

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
            'status' => CandidateStatus::class,
            'number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Ballot, $this>
     */
    public function ballot(): BelongsTo
    {
        return $this->belongsTo(Ballot::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function photoPath(string $size): ?string
    {
        if ($this->photo_key === null) {
            return null;
        }

        return config('voting.photo.directory')."/{$this->photo_key}-{$size}.webp";
    }

    /**
     * URL foto ukuran tertentu (thumb, card, large) atau null jika belum ada foto.
     */
    public function photoUrl(string $size = 'card'): ?string
    {
        $path = $this->photoPath($size);

        return $path === null ? null : Storage::disk(config('voting.photo.disk'))->url($path);
    }

    /**
     * Inisial untuk placeholder netral jika foto belum ada.
     */
    /**
     * Bentuk baku nama untuk membandingkan (huruf besar/kecil, tanda baca, spasi ganda diabaikan).
     */
    public static function normalizeName(string $name): string
    {
        return Str::of($name)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    /**
     * Apakah nama ini sudah dipakai calon lain di surat suara (dan RT) yang sama.
     */
    public static function nameTaken(int $ballotId, ?int $unitId, string $name, ?int $ignoreId = null): bool
    {
        $normalized = static::normalizeName($name);

        return static::query()
            ->where('ballot_id', $ballotId)
            ->where('unit_id', $unitId)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->pluck('name')
            ->contains(fn (string $existing): bool => static::normalizeName($existing) === $normalized);
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $word): string => Str::upper(Str::substr($word, 0, 1)))
            ->implode('');
    }

    public function displayNumber(): string
    {
        return str_pad((string) $this->number, 2, '0', STR_PAD_LEFT);
    }
}
