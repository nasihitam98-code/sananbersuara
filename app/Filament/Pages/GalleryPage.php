<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Candidates\CandidateResource;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\GalleryPhoto;
use App\Models\Unit;
use App\Models\User;
use App\Services\Candidates\CandidateGallery;
use App\Services\SiteLogoProcessor;
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
 * Media (Super Admin): satu tempat untuk semua gambar (foto calon dan logo). Unggah banyak gambar dulu, lalu pasang ke calon
 * (manual atau otomatis lewat nama file) atau jadikan logo. Gambar di Media privat dan bisa dibersihkan.
 */
class GalleryPage extends Page
{
    protected string $view = 'filament.pages.gallery-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'Lainnya';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Media';

    protected static ?string $title = 'Media';

    protected static ?string $slug = 'media';

    public bool $onlyUnused = false;

    /** Gambar yang diklik di grid; rincian dan tindakannya tampil di panel samping (seperti WordPress). */
    public ?string $selectedPhoto = null;

    public function selectPhoto(?string $publicId): void
    {
        $this->selectedPhoto = $this->selectedPhoto === $publicId ? null : $publicId;
    }

    public function selected(): ?GalleryPhoto
    {
        return $this->selectedPhoto === null
            ? null
            : GalleryPhoto::query()->with(['candidates.ballot', 'candidates.unit'])->where('public_id', $this->selectedPhoto)->first();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    /**
     * @return Collection<int, GalleryPhoto>
     */
    public function photos(): Collection
    {
        return GalleryPhoto::query()
            ->with(['candidates.ballot', 'candidates.unit'])
            ->when($this->onlyUnused, fn ($query) => $query->unused())
            ->latest()
            ->limit(300)
            ->get();
    }

    public function unusedCount(): int
    {
        return GalleryPhoto::query()->unused()->count();
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
            ->label('Unggah ke Media')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Unggah gambar ke Media')
            ->modalDescription('Pilih atau seret banyak gambar sekaligus (maks. '.intdiv((int) config('voting.photo.max_kilobytes'), 1024).' MB per gambar). Setelah masuk Media, pasang ke calon atau jadikan logo.')
            ->modalSubmitActionLabel('Simpan ke Media')
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
                    ->title("{$saved} gambar masuk Media.")
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
            ->modalDescription('Gambar di Media yang belum dipakai dipasang ke calon bila nama filenya diawali nomor urut (01.jpg) atau berisi nama calon (sutrisno.jpg). Yang tidak cocok tetap di Media untuk dipilih manual.')
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
            ->modalHeading(fn (): string => 'Hapus '.$this->unusedCount().' gambar yang tidak dipakai?')
            ->modalDescription('Gambar yang dipakai calon atau logo tidak ikut terhapus. Tindakan ini tidak bisa dibatalkan.')
            ->modalSubmitActionLabel('Ya, hapus')
            ->visible(fn (): bool => $this->unusedCount() > 0)
            ->action(function (): void {
                $count = app(CandidateGallery::class)->deleteUnused($this->user());
                Notification::make()->title("{$count} gambar dihapus dari Media.")->success()->send();
            });
    }

    public function assignAction(): Action
    {
        return Action::make('assign')
            ->label('Pasang')
            ->icon(Heroicon::OutlinedUserPlus)
            ->tooltip('Pasang gambar ini ke calon')
            ->size('sm')
            ->modalHeading('Pasang gambar ini ke calon')
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

    public function useAsLogoAction(): Action
    {
        return Action::make('useAsLogo')
            ->label('Jadikan logo')
            ->button()
            ->size('sm')
            ->icon(Heroicon::OutlinedSparkles)
            ->tooltip('Jadikan logo')
            ->color('gray')
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedSparkles)
            ->modalHeading('Jadikan gambar ini logo?')
            ->modalDescription('Logo tampil di halaman publik untuk semua orang, panel admin, HP pemilih, bilik, dan layar proyektor. Pastikan ini memang logo, bukan foto atau tangkapan layar.')
            ->modalSubmitActionLabel('Ya, jadikan logo')
            ->action(function (array $arguments): void {
                $photo = GalleryPhoto::query()->where('public_id', $arguments['photo'] ?? '')->firstOrFail();

                try {
                    app(SiteLogoProcessor::class)->useMedia($photo);
                } catch (RuntimeException $exception) {
                    Notification::make()->title('Logo tidak bisa dipakai')->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Logo dipasang.')->body('Sudah tampil di semua halaman.')->success()->send();
                // Muat ulang agar logo di header panel ikut berganti.
                $this->redirect(static::getUrl());
            });
    }

    public function deleteAction(): Action
    {
        return Action::make('delete')
            ->label('Hapus gambar')
            ->button()
            ->size('sm')
            ->icon(Heroicon::OutlinedTrash)
            ->tooltip('Hapus gambar')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Hapus gambar ini dari Media?')
            ->modalDescription('Calon atau logo yang sudah memakai gambar ini tetap memakainya. Tindakan ini tidak bisa dibatalkan.')
            ->modalSubmitActionLabel('Ya, hapus')
            ->action(function (array $arguments): void {
                $photo = GalleryPhoto::query()->where('public_id', $arguments['photo'] ?? '')->firstOrFail();
                app(CandidateGallery::class)->delete($photo, $this->user());
                Notification::make()->title('Gambar dihapus dari Media.')->success()->send();
            });
    }
}
