<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Voters\VoterResource;
use App\Models\User;
use App\Models\Voter;
use App\Services\Voters\VoterImport;
use App\Services\Voting\VotingException;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Import pemilih: unggah -> pratinjau + laporan error per baris -> konfirmasi -> simpan (bagian 5.8).
 *
 * @property-read Schema $form
 */
class ImportVoters extends Page
{
    protected string $view = 'filament.pages.import-voters';

    protected static ?string $title = 'Import Data Pemilih';

    protected static ?string $slug = 'pemilih/import';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Ringkasan pratinjau untuk tampilan saja (tanpa NIK/HP). Data lengkap disimpan di cache server,
     * karena properti publik Livewire ikut terkirim ke browser.
     *
     * @var array<int, array{line: int, name: string, unit: string, address: string, errors: array<int, string>, warnings: array<int, string>}>
     */
    public array $rows = [];

    public ?string $previewKey = null;

    public bool $includeWarnings = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('create', Voter::class);
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                FileUpload::make('file')
                    ->label('File Excel (.xlsx) atau CSV sesuai template')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/csv',
                        'text/plain',
                        'application/vnd.ms-excel',
                    ])
                    ->maxSize(5120)
                    ->disk('local')
                    ->directory('import-sementara')
                    ->visibility('private')
                    ->preserveFilenames(false)
                    ->required(),
            ]);
    }

    public function preview(): void
    {
        $path = $this->form->getState()['file'];
        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'csv';

        try {
            $fullRows = app(VoterImport::class)->preview(Storage::disk('local')->path($path), $extension, auth()->user());

            $this->previewKey = 'voter-import:'.auth()->id().':'.Str::uuid();
            Cache::put($this->previewKey, $fullRows, now()->addMinutes(30));

            $this->rows = collect($fullRows)
                ->map(fn (array $row): array => collect($row)->only(['line', 'name', 'unit', 'address', 'errors', 'warnings'])->all())
                ->all();
        } catch (VotingException $exception) {
            $this->rows = [];
            Notification::make()->title($exception->getMessage())->danger()->send();
        } finally {
            Storage::disk('local')->delete($path);
            $this->form->fill();
        }
    }

    public function confirmImport(): void
    {
        abort_unless(static::canAccess(), 403);

        $fullRows = $this->previewKey !== null && str_starts_with($this->previewKey, 'voter-import:'.auth()->id().':')
            ? Cache::pull($this->previewKey)
            : null;

        if (! is_array($fullRows)) {
            Notification::make()->title('Pratinjau kedaluwarsa. Unggah ulang file.')->warning()->send();
            $this->resetPreview();

            return;
        }

        try {
            $result = app(VoterImport::class)->import($fullRows, auth()->user(), $this->includeWarnings);
        } catch (VotingException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        $this->rows = [];
        $this->previewKey = null;
        Notification::make()
            ->title("{$result['imported']} pemilih diimpor, {$result['skipped']} baris dilewati.")
            ->success()
            ->send();

        $this->redirect(VoterResource::getUrl());
    }

    public function resetPreview(): void
    {
        if ($this->previewKey !== null) {
            Cache::forget($this->previewKey);
        }

        $this->rows = [];
        $this->previewKey = null;
    }

    /**
     * @return array{total: int, valid: int, warnings: int, errors: int}
     */
    public function summary(): array
    {
        $rows = collect($this->rows);

        return [
            'total' => $rows->count(),
            'valid' => $rows->filter(fn (array $row): bool => $row['errors'] === [] && $row['warnings'] === [])->count(),
            'warnings' => $rows->filter(fn (array $row): bool => $row['errors'] === [] && $row['warnings'] !== [])->count(),
            'errors' => $rows->filter(fn (array $row): bool => $row['errors'] !== [])->count(),
        ];
    }
}
