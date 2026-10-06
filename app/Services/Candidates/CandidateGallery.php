<?php

namespace App\Services\Candidates;

use App\Enums\BallotScope;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\GalleryPhoto;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CandidatePhotoProcessor;
use App\Services\Voting\VotingException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Galeri Foto calon: simpan foto dulu (privat, EXIF dibuang, diperkecil), lalu pilih untuk calon.
 * Memasang foto galeri ke calon memakai CandidatePhotoProcessor yang sama (potong persegi, 3 ukuran);
 * file galeri tetap ada sampai dihapus dari galeri.
 */
class CandidateGallery
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private AuditLogger $audit,
        private CandidatePhotoProcessor $photos,
        private CandidateBulkImporter $importer,
    ) {}

    /**
     * Simpan satu unggahan sementara (disk local) ke galeri. File sementara selalu dihapus.
     */
    public function store(string $temporaryPath, string $originalName, User $actor): GalleryPhoto
    {
        abort_unless($actor->isSuperAdmin(), 403);
        $disk = Storage::disk('local');
        $absolute = $disk->path($temporaryPath);

        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolute);

            if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
                throw new RuntimeException('Berkas bukan gambar JPG/PNG/WebP yang valid.');
            }

            $key = Str::lower(Str::random(40));
            $image = ImageManager::usingDriver(GdDriver::class)->decodePath($absolute)->orient()->scaleDown(1600, 1600);
            $width = $image->width();
            $height = $image->height();

            $disk->put(GalleryPhoto::DIRECTORY."/{$key}.webp", (string) $image->encode(new WebpEncoder(quality: 85, strip: true)));
            $disk->put(GalleryPhoto::DIRECTORY."/{$key}-thumb.webp", (string) $image->cover(240, 240)->encode(new WebpEncoder(quality: 80, strip: true)));

            $photo = new GalleryPhoto(['original_name' => Str::limit($originalName, 180, ''), 'file_key' => $key, 'width' => $width, 'height' => $height]);
            $photo->uploader()->associate($actor);
            $photo->save();
        } finally {
            $disk->delete($temporaryPath);
        }

        $this->audit->log('gallery.photo_added', $photo, meta: ['name' => $photo->original_name], actor: $actor);

        return $photo;
    }

    /**
     * Pasang foto galeri ke calon (menggantikan foto lama calon).
     */
    public function assign(Candidate $candidate, GalleryPhoto $photo, User $actor): void
    {
        abort_unless($actor->isSuperAdmin(), 403);

        if (! $candidate->ballot->election->status->allowsConfigurationChanges()) {
            throw VotingException::invalidState('Pemilihan sudah dimulai; foto calon tidak bisa diubah.');
        }

        $disk = Storage::disk('local');
        $temporary = 'unggahan-sementara/galeri-'.Str::lower(Str::random(20)).'.webp';
        $disk->copy($photo->path(), $temporary);

        // replace() menghapus file sementara dan mencatat audit penggantian foto.
        $this->photos->replace($candidate, $temporary);
        $candidate->forceFill(['gallery_photo_id' => $photo->id])->save();

        $this->audit->log('gallery.photo_assigned', $photo, $candidate->ballot->election, meta: [
            'candidate' => "{$candidate->displayNumber()} {$candidate->name}",
        ], actor: $actor);
    }

    /**
     * Pasang otomatis foto galeri yang belum dipakai ke calon surat suara ini, berdasarkan nama file
     * (nomor di depan atau nama calon). Calon yang sudah punya foto dari galeri dilewati.
     *
     * @return array{matched: array<int, string>, skipped: array<int, string>}
     */
    public function autoAssign(Ballot $ballot, ?int $unitId, User $actor): array
    {
        $candidates = Candidate::query()
            ->where('ballot_id', $ballot->id)
            ->where('unit_id', $ballot->scope === BallotScope::PerRt ? $unitId : null)
            ->get()
            ->keyBy('number');

        $matched = [];
        $skipped = [];

        foreach (GalleryPhoto::query()->whereDoesntHave('candidates')->oldest()->get() as $photo) {
            [$candidate, $reason] = $this->importer->matchPhoto($photo->original_name, $candidates);

            if ($candidate === null) {
                $skipped[] = "{$photo->original_name} ({$reason})";

                continue;
            }

            if ($candidate->gallery_photo_id !== null) {
                $skipped[] = "{$photo->original_name} (calon {$candidate->displayNumber()} sudah memakai foto galeri lain)";

                continue;
            }

            $this->assign($candidate, $photo, $actor);
            $candidate->refresh();
            $matched[] = "{$candidate->displayNumber()} {$candidate->name}";
        }

        return ['matched' => $matched, 'skipped' => $skipped];
    }

    public function delete(GalleryPhoto $photo, User $actor): void
    {
        abort_unless($actor->isSuperAdmin(), 403);

        Storage::disk('local')->delete([$photo->path(), $photo->path('thumb')]);
        $name = $photo->original_name;
        $photo->delete();

        $this->audit->log('gallery.photo_deleted', meta: ['name' => $name], actor: $actor);
    }

    /**
     * Hapus semua foto galeri yang tidak dipakai calon mana pun.
     */
    public function deleteUnused(User $actor): int
    {
        $unused = GalleryPhoto::query()->whereDoesntHave('candidates')->get();

        foreach ($unused as $photo) {
            $this->delete($photo, $actor);
        }

        return $unused->count();
    }
}
