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

#[Fillable(['unit_id', 'origin_unit_id', 'number', 'name', 'status'])]
#[RouteKey('public_id')]
class Candidate extends Model
{
    use HasFactory, HasUlids;

    /**
     * Asal RT selalu ikut dimuat (tabel RT kecil) agar tampilan surat suara/hasil tidak N+1.
     *
     * @var array<int, string>
     */
    protected $with = ['originUnit'];

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

    /**
     * Asal RT calon (keterangan, mis. calon RW perwakilan RT 03).
     *
     * @return BelongsTo<Unit, $this>
     */
    public function originUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'origin_unit_id');
    }

    /**
     * Teks asal RT untuk ditampilkan, mis. "RT 03", atau null bila tidak diisi.
     */
    public function originLabel(): ?string
    {
        return $this->originUnit?->name;
    }

    /**
     * Nama + asal RT untuk teks satu baris (berita acara, rekap), mis. "Bapak Joko (RT 03)".
     */
    public function nameWithOrigin(): string
    {
        return $this->originLabel() === null ? $this->name : "{$this->name} ({$this->originLabel()})";
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
     * Sapaan/gelar di depan nama yang diabaikan saat mencari nama kembar,
     * agar "Sutrisno" dan "Bapak Sutrisno" dianggap orang yang sama.
     */
    private const HONORIFICS = [
        'bapak', 'bpk', 'bp', 'pak', 'ibu', 'ibuk', 'bu', 'sdr', 'sdri', 'saudara', 'saudari',
        'h', 'hj', 'haji', 'hajah', 'hajjah', 'kh', 'ust', 'ustad', 'ustadz', 'ustadzah',
        'mbah', 'mas', 'mbak', 'dr', 'drs', 'dra', 'ir', 'prof',
    ];

    /**
     * Bentuk baku nama untuk membandingkan (huruf besar/kecil, tanda baca, spasi ganda diabaikan).
     */
    public static function normalizeName(string $name): string
    {
        return Str::of($name)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    /**
     * Inti nama untuk deteksi kembar: bentuk baku tanpa sapaan/gelar di depan.
     */
    public static function coreName(string $name): string
    {
        $words = explode(' ', static::normalizeName($name));

        while (count($words) > 1 && in_array($words[0], self::HONORIFICS, true)) {
            array_shift($words);
        }

        return implode(' ', $words);
    }

    /**
     * Calon lain di surat suara (dan RT) yang sama dengan nama yang dianggap sama, bila ada.
     */
    public static function sameNameAs(int $ballotId, ?int $unitId, string $name, ?int $ignoreId = null): ?self
    {
        $core = static::coreName($name);

        return static::query()
            ->where('ballot_id', $ballotId)
            ->where('unit_id', $unitId)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get()
            ->first(fn (self $existing): bool => static::coreName($existing->name) === $core);
    }

    public static function nameTaken(int $ballotId, ?int $unitId, string $name, ?int $ignoreId = null): bool
    {
        return static::sameNameAs($ballotId, $unitId, $name, $ignoreId) !== null;
    }

    /**
     * Inisial untuk placeholder netral jika foto belum ada.
     */
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
