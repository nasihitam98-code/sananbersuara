<?php

namespace App\Filament\Resources\Candidates\Pages;

use App\Filament\Resources\Candidates\CandidateResource;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Services\AuditLogger;
use App\Services\CandidatePhotoProcessor;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateCandidate extends CreateRecord
{
    protected static string $resource = CandidateResource::class;

    protected bool $createAnother = true;

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

        if ($ballot->max_candidates !== null && $ballot->candidates()->count() >= $ballot->max_candidates) {
            Notification::make()->title("Batas {$ballot->max_candidates} calon untuk surat suara ini sudah tercapai.")->danger()->send();

            throw new Halt;
        }

        $candidate = new Candidate($data);
        $candidate->ballot()->associate($ballot);
        $candidate->save();

        app(AuditLogger::class)->log('candidate.created', $candidate, $ballot->election, meta: $candidate->only(['number', 'name', 'status']));

        if (filled($data['photo_upload'] ?? null)) {
            try {
                app(CandidatePhotoProcessor::class)->replace($candidate, $data['photo_upload']);
            } catch (RuntimeException $exception) {
                Notification::make()->title('Foto ditolak: '.$exception->getMessage())->warning()->send();
            }
        }

        return $candidate;
    }
}
