<?php

namespace App\Filament\Resources\Candidates\Pages;

use App\Filament\Resources\Candidates\CandidateResource;
use App\Models\Candidate;
use App\Services\AuditLogger;
use App\Services\CandidatePhotoProcessor;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Candidate $record
 */
class EditCandidate extends EditRecord
{
    protected static string $resource = CandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('removePhoto')
                ->label('Hapus foto')
                ->color('gray')
                ->visible(fn (): bool => $this->record->photo_key !== null)
                ->requiresConfirmation()
                ->action(fn () => app(CandidatePhotoProcessor::class)->remove($this->record)),
            CandidateResource::deleteAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['gallery_photo_pick'] = $this->record->gallery_photo_id;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $before = $record->only(['number', 'name', 'status', 'origin_unit_id']);
        $upload = $data['photo_upload'] ?? null;
        $galleryPick = $data['gallery_photo_pick'] ?? null;
        unset($data['photo_upload'], $data['gallery_photo_pick']);

        $record->update($data);

        app(AuditLogger::class)->log('candidate.updated', $record, $record->ballot->election, meta: [
            'before' => $before,
            'after' => $record->only(['number', 'name', 'status', 'origin_unit_id']),
        ]);

        // Pilihan galeri hanya dipakai bila berbeda dari foto galeri yang sudah terpasang.
        CreateCandidate::attachPhoto($record, $upload, (int) $galleryPick === (int) $record->gallery_photo_id ? null : $galleryPick);

        return $record;
    }
}
