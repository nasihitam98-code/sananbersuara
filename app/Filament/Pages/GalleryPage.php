<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Candidates\CandidateResource;
use App\Filament\Support\Workspace;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\GalleryPhoto;
use App\Models\Unit;
use App\Models\User;
use App\Services\Candidates\CandidateGallery;
use App\Services\Voting\VotingException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use UnitEnum;

/**
 * Galeri Foto calon (Super Admin): unggah banyak foto dulu, lalu pilih untuk tiap calon,
 * atau pasang otomatis berdasarkan nama file. Foto galeri privat dan bisa dibersihkan.
 */
class GalleryPage extends Page
{
    protected string $view = 'filament.pages.gallery-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'Persiapan';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Galeri Foto';

    protected static ?string $title = 'Galeri Foto Calon';

    protected static ?string $slug = 'galeri-foto';

    public bool $onlyUnused = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Workspace::shows();
    }

    /**
     * @return Collection<int, GalleryPhoto>
     */
    public function photos(): Collection
    {
        return GalleryPhoto::query()
            ->with('candidates.ballot')
            ->when($this->onlyUnused, fn ($query) => $query->whereDoesntHave('candidates'))
            ->latest()
            ->limit(300)
            ->get();
    }

    public function unusedCount(): int
    {
        return GalleryPhoto::query()->whereDoesntHave('candidates')->count();
    }

    public function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function uploadAction(): Action
    {
        return Action::make('upload')
            ->label('Unggah ke galeri')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Unggah foto ke galeri')
            ->modalDescription('Pilih atau seret banyak foto sekaligus (maks. '.intdiv((int) config('voting.photo.max_kilobytes'), 1024).' MB per foto). Setelah masuk galeri, pilih foto untuk tiap calon.')
            ->modalSubmitActionLabel('Simpan ke galeri')
            ->schema([
                FileUpload::make('photos')
                    ->label('Foto')
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
                $gallery = app(CandidateGallery::class);
                $saved = 0;
                $failed = [];

                foreach ($data['photos'] ?? [] as $path) {
                    $name = $data['photo_names'][$path] ?? basename($path);

                    try {
                        $gallery->store($path, $name, $this->user());
                        $saved++;
                    } catch (RuntimeException $exception) {
                        $failed[] = "{$name} ({$exception->getMessage()})";
                    }
                }

                Notification::make()
                    ->title("{$saved} foto masuk galeri.")
                    ->body($failed === [] ? null : 'Gagal: '.Str::limit(implode(', ', $failed), 400))
                    ->color($failed === [] ? 'success' : 'warning')
                    ->send();
            });
    }

    public function autoAssignAction(): Action
    {
        return Action::make('autoAssign')
            ->label('Pasang otomatis')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->modalHeading('Pasang otomatis berdasarkan nama file')
            ->modalDescription('Foto galeri yang belum dipakai dipasang ke calon bila nama filenya diawali nomor urut (01.jpg) atau berisi nama calon (sutrisno.jpg). Yang tidak cocok tetap di galeri untuk dipilih manual.')
            ->modalSubmitActionLabel('Pasang')
            ->schema([
                Select::make('ballot_id')
                    ->label('Surat suara')
                    ->options(fn (): array => CandidateResource::editableBallotOptions())
                    ->default(fn (): ?int => CandidateResource::defaultBallotId())
                    ->in(fn (): array => array_keys(CandidateResource::editableBallotOptions()))
                    ->required()
                    ->live(),
                Select::make('unit_id')
                    ->label('RT calon')
                    ->options(fn (): array => Unit::query()->orderBy('sort')->pluck('name', 'id')->all())
                    ->visible(fn (Get $get): bool => CandidateResource::ballotIsPerUnit($get('ballot_id')))
                    ->required(fn (Get $get): bool => CandidateResource::ballotIsPerUnit($get('ballot_id'))),
            ])
            ->action(function (array $data): void {
                try {
                    $result = app(CandidateGallery::class)->autoAssign(Ballot::query()->with('election')->findOrFail($data['ballot_id']), $data['unit_id'] ?? null, $this->user());
                } catch (VotingException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(count($result['matched']).' foto dipasang.')
                    ->body($result['skipped'] === [] ? null : 'Tidak cocok (pilih manual): '.Str::limit(implode(', ', $result['skipped']), 400))
                    ->color($result['skipped'] === [] ? 'success' : 'warning')
                    ->duration($result['skipped'] === [] ? 6000 : 'persistent')
                    ->send();
            });
    }

    public function deleteUnusedAction(): Action
    {
        return Action::make('deleteUnused')
            ->label('Hapus yang tidak dipakai')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Hapus '.$this->unusedCount().' foto yang tidak dipakai?')
            ->modalDescription('Foto yang sudah dipasang ke calon tidak ikut terhapus. Tindakan ini tidak bisa dibatalkan.')
            ->modalSubmitActionLabel('Ya, hapus')
            ->visible(fn (): bool => $this->unusedCount() > 0)
            ->action(function (): void {
                $count = app(CandidateGallery::class)->deleteUnused($this->user());
                Notification::make()->title("{$count} foto dihapus dari galeri.")->success()->send();
            });
    }

    public function assignAction(): Action
    {
        return Action::make('assign')
            ->label('Pasang ke calon…')
            ->size('sm')
            ->modalHeading('Pasang foto ini ke calon')
            ->modalDescription('Foto lama calon (bila ada) digantikan. Foto dipotong persegi otomatis.')
            ->modalSubmitActionLabel('Pasang')
            ->schema([
                Select::make('candidate_id')
                    ->label('Calon')
                    ->options(fn (): array => CandidateResource::editableCandidateOptions())
                    ->in(fn (): array => array_keys(CandidateResource::editableCandidateOptions()))
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                $photo = GalleryPhoto::query()->where('public_id', $arguments['photo'] ?? '')->firstOrFail();
                $candidate = Candidate::query()->with('ballot.election')->findOrFail($data['candidate_id']);

                try {
                    app(CandidateGallery::class)->assign($candidate, $photo, $this->user());
                } catch (VotingException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title("Foto dipasang ke {$candidate->displayNumber()} {$candidate->name}.")->success()->send();
            });
    }

    public function deleteAction(): Action
    {
        return Action::make('delete')
            ->label('Hapus')
            ->size('sm')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Hapus foto ini dari galeri?')
            ->modalDescription('Calon yang sudah memakai foto ini tetap memakai fotonya. Tindakan ini tidak bisa dibatalkan.')
            ->modalSubmitActionLabel('Ya, hapus')
            ->action(function (array $arguments): void {
                $photo = GalleryPhoto::query()->where('public_id', $arguments['photo'] ?? '')->firstOrFail();
                app(CandidateGallery::class)->delete($photo, $this->user());
                Notification::make()->title('Foto dihapus dari galeri.')->success()->send();
            });
    }
}
