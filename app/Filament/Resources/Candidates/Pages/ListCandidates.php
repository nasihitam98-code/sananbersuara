<?php

namespace App\Filament\Resources\Candidates\Pages;

use App\Filament\Resources\Candidates\CandidateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCandidates extends ListRecords
{
    protected static string $resource = CandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah calon')
                ->url(fn (): string => CandidateResource::getUrl('create', array_filter(['surat_suara' => $this->tableFilters['ballot_id']['value'] ?? null]))),
        ];
    }
}
