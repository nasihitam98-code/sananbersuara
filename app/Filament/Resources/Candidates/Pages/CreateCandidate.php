<?php

namespace App\Filament\Resources\Candidates\Pages;

use App\Enums\BallotScope;
use App\Enums\CandidateStatus;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\GalleryPhoto;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CandidatePhotoProcessor;
use App\Services\Candidates\CandidateGallery;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateCandidate extends CreateRecord
{
    protected static string $resource = CandidateResource::class;

    protected bool $createAnother = true;

    /**
     * Di bawah form: calon yang sudah ada di surat suara terpilih, agar terlihat kemajuannya
     * saat menambah satu per satu ("Buat & buat lainnya").
     */
    public function getFooter(): ?View
    {
        $ballot = filled($this->data['ballot_id'] ?? null) ? Ballot::query()->find($this->data['ballot_id']) : null;

        if ($ballot === null) {
            return null;
        }

        $perUnit = $ballot->scope === BallotScope::PerRt;
        $unitId = $perUnit ? ($this->data['unit_id'] ?? null) : null;

        return view('filament.resources.candidates.ballot-candidates', [
            'ballot' => $ballot,
            'needsUnit' => $perUnit && blank($unitId),
            'candidates' => $perUnit && blank($unitId)
                ? collect()
                : $ballot->candidates()->where('unit_id', $unitId)->orderBy('number')->get(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $ballot = Ballot::query()->with('election')->findOrFail($data['ballot_id']);

        if (! $ballot->election->status->allowsConfigurationChanges()) {
            Notification::make()->title('Pemilihan sudah dimulai; calon tidak bisa ditambah.')->danger()->send();

            throw new Halt;
        }

        $perUnit = $ballot->scope === BallotScope::PerRt;
        $data['unit_id'] = $perUnit ? ($data['unit_id'] ?? null) : null;
        $data['origin_unit_id'] = $perUnit ? null : ($data['origin_unit_id'] ?? null);

        if ($perUnit && $data['unit_id'] === null) {
            Notification::make()->title('Pilih RT calon untuk surat suara per RT.')->danger()->send();

            throw new Halt;
        }

        $existing = $ballot->candidates()->when($perUnit, fn ($query) => $query->where('unit_id', $data['unit_id']))->count();

        if ($ballot->max_candidates !== null && $existing >= $ballot->max_candidates) {
            $scopeLabel = $perUnit ? 'per RT untuk RT ini' : 'untuk surat suara ini';
            Notification::make()->title("Batas {$ballot->max_candidates} calon {$scopeLabel} sudah tercapai.")->danger()->send();

            throw new Halt;
        }

        $data['status'] ??= CandidateStatus::Aktif;
        $upload = $data['photo_upload'] ?? null;
        $galleryPick = $data['gallery_photo_pick'] ?? null;
        unset($data['photo_upload'], $data['gallery_photo_pick']);

        $candidate = new Candidate($data);
        $candidate->ballot()->associate($ballot);
        $candidate->save();

        app(AuditLogger::class)->log('candidate.created', $candidate, $ballot->election, meta: $candidate->only(['number', 'name', 'status']));

        static::attachPhoto($candidate, $upload, $galleryPick);

        return $candidate;
    }

    /**
     * Foto calon dari unggahan baru, atau (bila tidak ada) dari Galeri Foto. Dipakai juga saat Ubah.
     */
    public static function attachPhoto(Candidate $candidate, mixed $upload, mixed $galleryPick): void
    {
        try {
            if (filled($upload)) {
                app(CandidatePhotoProcessor::class)->replace($candidate, $upload);
            } elseif (filled($galleryPick)) {
                /** @var User $user */
                $user = auth()->user();
                app(CandidateGallery::class)->assign($candidate, GalleryPhoto::query()->findOrFail($galleryPick), $user);
            }
        } catch (RuntimeException $exception) {
            Notification::make()->title('Foto ditolak: '.$exception->getMessage())->warning()->send();
        }
    }
}
