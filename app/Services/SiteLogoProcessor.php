<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\GalleryPhoto;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Logo dari Pengaturan Tampilan: validasi MIME asli, re-encode ke WebP (metadata terbuang, transparansi tetap),
 * diperkecil tanpa dipotong (logo lebar tetap utuh), nama file acak di disk "public". Logo lama dihapus.
 */
class SiteLogoProcessor
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const DIRECTORY = 'tampilan';

    private const MAX_PIXELS = 512;

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  string  $sourcePath  Path file unggahan sementara di disk "local".
     */
    public function replace(string $sourcePath): void
    {
        $absolute = Storage::disk('local')->path($sourcePath);

        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolute);

            if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
                throw new RuntimeException('Berkas bukan gambar JPG/PNG/WebP yang valid.');
            }

            $image = ImageManager::usingDriver(GdDriver::class)
                ->decodePath($absolute)
                ->orient()
                ->scaleDown(self::MAX_PIXELS, self::MAX_PIXELS);

            $path = self::DIRECTORY.'/logo-'.Str::lower(Str::random(32)).'.webp';
            Storage::disk('public')->put($path, (string) $image->encode(new WebpEncoder(quality: 90, strip: true)));

            $old = AppSetting::get(AppSetting::LOGO_PATH);
            AppSetting::put([AppSetting::LOGO_PATH => $path, AppSetting::LOGO_MEDIA_ID => null]);
            $this->deleteFile($old);

            $this->audit->log('settings.logo_replaced', meta: ['had_previous' => $old !== null]);
        } finally {
            Storage::disk('local')->delete($sourcePath);
        }
    }

    /**
     * Pakai gambar dari Media sebagai logo. Logo yang tampil adalah salinan publiknya; gambar di Media tetap privat.
     */
    public function useMedia(GalleryPhoto $photo): void
    {
        $temporary = 'unggahan-sementara/logo-'.Str::lower(Str::random(20)).'.webp';
        Storage::disk('local')->copy($photo->path(), $temporary);

        $this->replace($temporary);
        AppSetting::put([AppSetting::LOGO_MEDIA_ID => (string) $photo->id]);
    }

    public function remove(): void
    {
        $this->deleteFile(AppSetting::get(AppSetting::LOGO_PATH));
        AppSetting::put([AppSetting::LOGO_PATH => null, AppSetting::LOGO_MEDIA_ID => null]);

        $this->audit->log('settings.logo_removed');
    }

    private function deleteFile(?string $path): void
    {
        if ($path !== null && str_starts_with($path, self::DIRECTORY.'/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
