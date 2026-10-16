<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Gambar di Media: satu tempat untuk semua gambar (foto calon, logo). Disimpan privat di disk local,
 * hanya untuk Super Admin; baru tampil ke warga setelah dipasang (salinannya) sebagai foto calon atau logo.
 */
#[Fillable(['original_name', 'file_key', 'width', 'height'])]
#[RouteKey('public_id')]
class GalleryPhoto extends Model
{
    use HasUlids;

    public const DIRECTORY = 'galeri-calon';

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Calon yang memakai gambar ini.
     *
     * @return HasMany<Candidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
    }

    /**
     * Gambar yang tidak dipakai calon mana pun dan bukan sumber logo.
     *
     * @param  Builder<GalleryPhoto>  $query
     */
    #[Scope]
    protected function unused(Builder $query): void
    {
        $query->whereDoesntHave('candidates')
            ->when(AppSetting::logoMediaId(), fn (Builder $query, int $logoId): Builder => $query->whereKeyNot($logoId));
    }

    public function isLogo(): bool
    {
        return AppSetting::logoMediaId() === $this->id;
    }

    /**
     * Keterangan dipakai di mana, mis. ["Logo", "Calon 01 Budi Santoso"].
     *
     * @return array<int, string>
     */
    public function usageLabels(): array
    {
        return collect($this->isLogo() ? ['Logo'] : [])
            ->merge($this->candidates->map(fn (Candidate $candidate): string => "Calon {$candidate->displayNumber()} {$candidate->name}"))
            ->all();
    }

    /**
     * Path file di disk local: ukuran "full" (sisi terpanjang maks. 1600 px) atau "thumb".
     */
    public function path(string $size = 'full'): string
    {
        return self::DIRECTORY."/{$this->file_key}".($size === 'thumb' ? '-thumb' : '').'.webp';
    }

    public function previewUrl(string $size = 'thumb'): string
    {
        return route('gallery.preview', ['galleryPhoto' => $this->public_id, 'size' => $size]);
    }
}
