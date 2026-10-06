<?php

namespace App\Filament\Resources\Voters\Pages;

use App\Filament\Resources\Voters\VoterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVoters extends ListRecords
{
    protected static string $resource = VoterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tambah pemilih'),
        ];
    }
}
