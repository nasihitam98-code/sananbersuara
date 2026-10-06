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
                ->modalDescription('Tempel daftar calon, satu calon per baris (bisa disalin dari Excel, WhatsApp, atau Word). Nomor urut otomatis. Nama yang sama/mirip dengan calon lain ditolak.')
                ->modalSubmitActionLabel('Simpan semua')
                ->schema([
                    ...$this->ballotFields(),
                    Select::make('default_origin_unit_id')
                        ->label('Asal RT untuk semua baris (opsional)')
                        ->helperText('Dipakai untuk baris yang tidak menulis RT sendiri.')
                        ->options(fn (): array => Unit::query()->orderBy('sort')->pluck('name', 'id')->all())
                        ->placeholder('Tidak diisi')
                        ->visible(fn (Get $get): bool => filled($get('ballot_id')) && ! CandidateResource::ballotIsPerUnit($get('ballot_id'))),
                    Textarea::make('names')
                        ->label('Daftar calon')
                        ->placeholder("Bapak Sutrisno | RT 01\nIbu Sumiati | RT 02\nBapak Ahmad Fauzi\n10. Bapak Joko | RT 05")
                        ->helperText('Format per baris: Nama, atau Nama | RT 03, atau Nomor | Nama | RT 03. Bisa juga tempel 2–3 kolom langsung dari Excel. RT boleh ditulis "RT 03", "03", atau "3".')
                        ->rows(12)
                        ->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        $count = app(CandidateBulkImporter::class)->import(
                            $this->ballot($data),
                            $data['unit_id'] ?? null,
                            $data['names'],
                            auth()->user(),
                            filled($data['default_origin_unit_id'] ?? null) ? (int) $data['default_origin_unit_id'] : null,
                        );
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
                ->modalDescription('Foto dicocokkan otomatis lewat nama file: nomor urut di depan (01.jpg, "3 - Bapak Ahmad.jpg") atau nama calon ("Ibu Sumiati.jpg", "sutrisno.png"). Foto dipotong persegi dan menggantikan foto lama. Yang tidak cocok dilewati dan disebutkan.')
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
                        ->duration($result['skipped'] === [] ? 6000 : 'persistent')
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
