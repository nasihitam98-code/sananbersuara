<?php

namespace App\Filament\Resources\Candidates\Pages;

use App\Filament\Resources\Candidates\CandidateResource;
use App\Models\Ballot;
use App\Models\Unit;
use App\Services\Candidates\CandidateBulkImporter;
use App\Services\Voting\VotingException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class ListCandidates extends ListRecords
{
    protected static string $resource = CandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bulkAdd')
                ->label('Tambah banyak calon')
                ->icon(Heroicon::OutlinedQueueList)
                ->color('gray')
                ->visible(fn (): bool => CandidateResource::canCreate())
                ->modalHeading('Tambah banyak calon sekaligus')
                ->modalDescription('Tempel daftar nama, satu calon per baris (bisa disalin dari Excel, WhatsApp, atau Word). Nomor urut otomatis, atau tulis nomornya di depan, mis. "5. Bapak Joko".')
                ->modalSubmitActionLabel('Simpan semua')
                ->schema([
                    ...$this->ballotFields(),
                    Textarea::make('names')
                        ->label('Daftar calon')
                        ->placeholder("Bapak Sutrisno\nIbu Sumiati\nBapak Ahmad Fauzi")
                        ->helperText('Satu nama per baris. Baris kosong diabaikan.')
                        ->rows(12)
                        ->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        $count = app(CandidateBulkImporter::class)->import($this->ballot($data), $data['unit_id'] ?? null, $data['names'], auth()->user());
                    } catch (VotingException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->persistent()->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()->title("{$count} calon ditambahkan.")->success()->send();
                }),

            Action::make('bulkPhotos')
                ->label('Unggah banyak foto')
                ->icon(Heroicon::OutlinedPhoto)
                ->color('gray')
                ->visible(fn (): bool => CandidateResource::canCreate())
                ->modalHeading('Unggah banyak foto sekaligus')
                ->modalDescription('Beri nama file dengan nomor urut calon di depan: 01.jpg, 2.png, "3 - Bapak Ahmad.jpg", dst. Foto otomatis dipotong persegi dan menggantikan foto lama.')
                ->modalSubmitActionLabel('Pasang foto')
                ->schema([
                    ...$this->ballotFields(),
                    FileUpload::make('photos')
                        ->label('Foto calon')
                        ->multiple()
                        ->maxFiles(100)
                        ->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize((int) config('voting.photo.max_kilobytes'))
                        ->disk('local')
                        ->directory('unggahan-sementara')
                        ->visibility('private')
                        ->storeFileNamesIn('photo_names')
                        ->required(),
                    Hidden::make('photo_names'),
                ])
                ->action(function (array $data): void {
                    $files = collect($data['photos'] ?? [])
                        ->mapWithKeys(fn (string $path): array => [$path => $data['photo_names'][$path] ?? basename($path)])
                        ->all();

                    try {
                        $result = app(CandidateBulkImporter::class)->attachPhotos($this->ballot($data), $data['unit_id'] ?? null, $files, auth()->user());
                    } catch (VotingException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()
                        ->title(count($result['matched']).' foto terpasang.')
                        ->body($result['skipped'] === [] ? null : 'Dilewati: '.Str::limit(implode(', ', $result['skipped']), 400))
                        ->color($result['skipped'] === [] ? 'success' : 'warning')
                        ->persistent($result['skipped'] !== [])
                        ->send();
                }),

            CreateAction::make()
                ->label('Tambah calon')
                ->url(fn (): string => CandidateResource::getUrl('create', array_filter(['surat_suara' => $this->tableFilters['ballot_id']['value'] ?? null]))),
        ];
    }

    /**
     * Pilihan surat suara (+ RT bila surat suara per RT) untuk aksi massal.
     *
     * @return array<int, Select>
     */
    private function ballotFields(): array
    {
        return [
            Select::make('ballot_id')
                ->label('Surat suara')
                ->options(fn (): array => CandidateResource::editableBallotOptions())
                ->default(fn (): ?int => ((int) ($this->tableFilters['ballot_id']['value'] ?? 0)) ?: CandidateResource::defaultBallotId())
                ->in(fn (): array => array_keys(CandidateResource::editableBallotOptions()))
                ->required()
                ->live(),
            Select::make('unit_id')
                ->label('RT calon')
                ->options(fn (): array => Unit::query()->orderBy('sort')->pluck('name', 'id')->all())
                ->visible(fn (Get $get): bool => CandidateResource::ballotIsPerUnit($get('ballot_id')))
                ->required(fn (Get $get): bool => CandidateResource::ballotIsPerUnit($get('ballot_id'))),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ballot(array $data): Ballot
    {
        return Ballot::query()->with('election')->findOrFail($data['ballot_id']);
    }
}
