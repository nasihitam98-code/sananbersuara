<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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
