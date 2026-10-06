<?php

namespace App\Filament\Resources\Candidates\Pages;

use App\Filament\Resources\Candidates\CandidateResource;
use App\Models\Candidate;
use App\Services\AuditLogger;
use App\Services\CandidatePhotoProcessor;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

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
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $before = $record->only(['number', 'name', 'status']);
        $upload = $data['photo_upload'] ?? null;
        unset($data['photo_upload']);

        $record->update($data);

        app(AuditLogger::class)->log('candidate.updated', $record, $record->ballot->election, meta: [
            'before' => $before,
            'after' => $record->only(['number', 'name', 'status']),
        ]);

        if (filled($upload)) {
            try {
                app(CandidatePhotoProcessor::class)->replace($record, $upload);
            } catch (RuntimeException $exception) {
                Notification::make()->title('Foto ditolak: '.$exception->getMessage())->warning()->send();
            }
        }

        return $record;
    }
}
