<?php

namespace App\Filament\Resources\Elections\Pages;

use App\Enums\ElectionMode;
use App\Filament\Resources\Elections\ElectionResource;
use App\Filament\Support\Workspace;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateElection extends CreateRecord
{
    protected static string $resource = ElectionResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $data['mode'] ??= Workspace::current() ?? ElectionMode::Dadakan;
        $data['settings'] = ElectionResource::mergeSettings($data['settings'] ?? null);
        $record = new ($this->getModel())($data);
        $record->created_by = auth()->id();
        $record->save();

        app(AuditLogger::class)->log('election.created', $record, $record, meta: ['mode' => $record->mode->value]);

        // Pemilihan baru langsung menjadi pemilihan yang sedang dikerjakan.
        if ($record->mode === Workspace::current()) {
            Workspace::chooseElection($record);
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
