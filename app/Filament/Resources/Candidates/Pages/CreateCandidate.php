<?php

namespace App\Filament\Resources\Candidates\Pages;

use App\Enums\BallotScope;
use App\Enums\CandidateStatus;
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

        $perUnit = $ballot->scope === BallotScope::PerRt;
        $data['unit_id'] = $perUnit ? ($data['unit_id'] ?? null) : null;

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
