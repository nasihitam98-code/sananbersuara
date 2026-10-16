<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Pengaturan tampilan sederhana (kunci → nilai) yang diubah dari menu Pengaturan Tampilan.
 */
#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    public const CACHE_KEY = 'app_settings';

    public const SITE_NAME = 'site_name';

    public const AREA_NAME = 'area_name';

    public const PUBLIC_NOTICE = 'public_notice';

    /** Path logo (WebP hasil olahan SiteLogoProcessor) di disk "public"; kosong = logo bawaan. */
    public const LOGO_PATH = 'logo_path';

    /** ID gambar di Media yang menjadi sumber logo (untuk keterangan "Dipakai: Logo"). */
    public const LOGO_MEDIA_ID = 'logo_media_id';

    /** Ikon tab browser bawaan (kotak merah bercentang) bila belum ada logo. */
    public const DEFAULT_FAVICON = 'favicon.svg';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @return array<string, ?string>
     */
    public static function allValues(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => static::query()->pluck('value', 'key')->all());
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = static::allValues()[$key] ?? null;

        return filled($value) ? $value : $default;
    }

    /**
     * Alamat logo yang diunggah di Pengaturan Tampilan, atau null bila memakai logo bawaan.
     */
    public static function logoUrl(): ?string
    {
        $path = static::get(self::LOGO_PATH);

        return $path === null ? null : Storage::disk('public')->url($path);
    }

    public static function logoMediaId(): ?int
    {
        $id = static::get(self::LOGO_MEDIA_ID);

        return $id === null ? null : (int) $id;
    }

    /**
     * Ikon tab browser: logo yang diunggah, atau kotak merah bercentang bawaan.
     */
    public static function faviconUrl(): string
    {
        return static::logoUrl() ?? asset(self::DEFAULT_FAVICON);
    }

    /**
     * @param  array<string, ?string>  $values
     */
    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            static::query()->updateOrCreate(['key' => $key], ['value' => filled($value) ? trim((string) $value) : null]);
        }

        Cache::forget(self::CACHE_KEY);
    }
}
