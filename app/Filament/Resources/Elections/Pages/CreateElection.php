<?php

namespace App\Filament\Resources\Elections\Pages;

use App\Filament\Resources\Elections\ElectionResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateElection extends CreateRecord
{
    protected static string $resource = ElectionResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $data['settings'] = ElectionResource::mergeSettings($data['settings'] ?? null);
        $record = new ($this->getModel())($data);
        $record->created_by = auth()->id();
        $record->save();

        app(AuditLogger::class)->log('election.created', $record, $record, meta: ['mode' => $record->mode->value]);

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
