<?php

namespace App\Services;

use App\Models\Candidate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Foto kandidat (bagian 10B): validasi MIME asli, re-encode ke WebP (metadata/EXIF terbuang),
 * dipotong persegi seragam dalam tiga ukuran, nama file acak.
 */
class CandidatePhotoProcessor
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  string  $sourcePath  Path file unggahan sementara di disk "local".
     */
    public function replace(Candidate $candidate, string $sourcePath): void
    {
        $absolute = Storage::disk('local')->path($sourcePath);

        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolute);

            if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
                throw new RuntimeException('Berkas bukan gambar JPG/PNG/WebP yang valid.');
            }

            $oldKey = $candidate->photo_key;
            $newKey = Str::lower(Str::random(40));
            $disk = Storage::disk(config('voting.photo.disk'));
            $manager = ImageManager::usingDriver(GdDriver::class);

            // Foto HP bisa belasan megapiksel: baca sekali, potong persegi di ukuran terbesar,
            // lalu ukuran lain diturunkan dari hasil itu (hemat memori dan waktu).
            $sizes = config('voting.photo.sizes');
            $largest = max($sizes);
            $base = $manager->decodePath($absolute)->orient()->cover($largest, $largest);

            foreach ($sizes as $size => $pixels) {
                $image = $pixels === $largest ? $base : (clone $base)->resize($pixels, $pixels);
                $encoded = $image->encode(new WebpEncoder(quality: 82, strip: true));

                $disk->put(config('voting.photo.directory')."/{$newKey}-{$size}.webp", (string) $encoded);
            }

            $candidate->forceFill(['photo_key' => $newKey, 'gallery_photo_id' => null])->save();
            $this->deleteFiles($oldKey);

            $this->audit->log('candidate.photo_replaced', $candidate, $candidate->ballot->election, meta: [
                'had_previous' => $oldKey !== null,
            ]);
        } finally {
            Storage::disk('local')->delete($sourcePath);
        }
    }

    public function remove(Candidate $candidate): void
    {
        $this->deleteFiles($candidate->photo_key);
        $candidate->forceFill(['photo_key' => null, 'gallery_photo_id' => null])->save();

        $this->audit->log('candidate.photo_removed', $candidate, $candidate->ballot->election);
    }

    private function deleteFiles(?string $key): void
    {
        if ($key === null) {
            return;
        }

        $directory = config('voting.photo.directory');

        Storage::disk(config('voting.photo.disk'))->delete(
            collect(array_keys(config('voting.photo.sizes')))->map(fn (string $size): string => "{$directory}/{$key}-{$size}.webp")->all()
        );
    }
}
