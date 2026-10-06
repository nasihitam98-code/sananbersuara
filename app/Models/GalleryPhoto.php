<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Foto di Galeri Foto calon (disimpan privat di disk local, hanya untuk Super Admin).
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
     * Calon yang memakai foto ini.
     *
     * @return HasMany<Candidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
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
