<?php

namespace App\Filament\Resources\Elections;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Filament\Resources\Elections\Pages\CreateElection;
use App\Filament\Resources\Elections\Pages\EditElection;
use App\Filament\Resources\Elections\Pages\ListElections;
use App\Filament\Resources\Elections\RelationManagers\BallotsRelationManager;
use App\Filament\Resources\Elections\RelationManagers\HistoryRelationManager;
use App\Filament\Resources\Elections\RelationManagers\StaffRelationManager;
use App\Filament\Support\QuickStart;
use App\Filament\Support\Workspace;
use App\Models\Candidate;
use App\Models\Election;
use App\Services\AuditLogger;
use App\Services\CandidatePhotoProcessor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ElectionResource extends Resource
{
    protected static ?string $model = Election::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Persiapan';

    protected static ?string $modelLabel = 'pemilihan';

    protected static ?string $pluralModelLabel = 'Pemilihan';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return Workspace::shows();
    }

    /**
     * Isian pengaturan yang dikosongkan dibuang agar nilai bawaan yang dipakai, dan kunci
     * pengaturan lain yang tidak ada di form (mis. hitung kepala) tetap dipertahankan.
     *
     * @param  array<string, mixed>|null  $submitted
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    public static function mergeSettings(?array $submitted, ?array $existing = null): array
    {
        $filled = array_filter($submitted ?? [], fn (mixed $value): bool => $value !== null && $value !== '');
        $formKeys = array_keys($submitted ?? []);

        return array_merge(array_diff_key($existing ?? [], array_flip($formKeys)), $filled);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(static::settingsComponents());
    }

    /**
     * Isian nama, mode, dan pengaturan lanjutan. Dipakai form Buat pemilihan dan jendela
     * "Nama & pengaturan" di halaman pemilihan.
     *
     * @return array<int, Section>
     */
    public static function settingsComponents(): array
    {
        $locked = fn (?Election $record): bool => $record !== null && ! $record->status->allowsConfigurationChanges();

        return [
            Section::make('Data pemilihan')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nama pemilihan')
                        ->placeholder('Contoh: Penjaringan Calon Ketua RW 2026')
                        ->required()
                        ->maxLength(150)
                        ->disabled($locked),
                    Select::make('mode')
                        ->label('Mode')
                        ->options(ElectionMode::class)
                        ->default(fn (): ElectionMode => Workspace::current() ?? ElectionMode::Dadakan)
                        ->helperText('Dadakan: QR + nama + PIN (rapat). Resmi: Meja Izin + laptop bilik per RT.')
                        // Di dalam mode kerja, mode sudah jelas: tidak perlu ditampilkan atau dipilih lagi.
                        ->visible(fn (): bool => Workspace::current() === null)
                        ->required()
                        ->live()
                        ->disabled(fn (?Election $record): bool => $record !== null && $record->status !== ElectionStatus::Draft),
                ]),
            Section::make('Pengaturan lanjutan Mode Resmi (boleh dibiarkan)')
                ->description('Kosongkan untuk memakai nilai bawaan. Bisa diubah selama status Draf atau Siap.')
                ->collapsible()
                ->collapsed()
                ->columns(3)
                ->visible(fn (Get $get): bool => in_array($get('mode') ?? Workspace::current(), [ElectionMode::Resmi, ElectionMode::Resmi->value], true))
                ->schema([
                    TextInput::make('settings.max_booths_per_unit')
                        ->label('Jumlah bilik per RT')
                        ->numeric()->minValue(1)->maxValue(10)
                        ->placeholder('Bawaan: '.config('voting.defaults.max_booths_per_unit')),
                    TextInput::make('settings.permit_expiry_minutes')
                        ->label('Izin hangus jika bilik tidak disentuh (menit)')
                        ->numeric()->minValue(1)->maxValue(30)
                        ->placeholder('Bawaan: '.config('voting.defaults.permit_expiry_minutes')),
                    TextInput::make('settings.booth_idle_minutes')
                        ->label('Sesi bilik berakhir jika diam (menit)')
                        ->numeric()->minValue(1)->maxValue(30)
                        ->placeholder('Bawaan: '.config('voting.defaults.booth_idle_minutes')),
                    TextInput::make('settings.booth_token_minutes')
                        ->label('Masa berlaku token bilik (menit)')
                        ->numeric()->minValue(1)->maxValue(60)
                        ->placeholder('Bawaan: '.config('voting.defaults.booth_token_minutes')),
                    TextInput::make('settings.desk_token_hours')
                        ->label('Masa berlaku token meja (jam)')
                        ->numeric()->minValue(1)->maxValue(72)
                        ->placeholder('Bawaan: '.config('voting.defaults.desk_token_hours')),
                ])
                ->disabled($locked),
            Section::make('Pengaturan lanjutan Mode Dadakan (boleh dibiarkan)')
                ->description('Kosongkan untuk memakai nilai bawaan. Bisa diubah selama status Draf atau Siap.')
                ->collapsible()
                ->collapsed()
                ->columns(3)
                ->visible(fn (Get $get): bool => ! in_array($get('mode') ?? Workspace::current(), [ElectionMode::Resmi, ElectionMode::Resmi->value], true))
                ->schema([
                    TextInput::make('settings.wave_minutes')
                        ->label('Durasi gelombang (menit)')
                        ->helperText('Surat suara dengan ≤ 10 calon.')
                        ->numeric()->minValue(1)->maxValue(60)
                        ->placeholder('Bawaan: '.config('voting.defaults.wave_minutes')),
                    TextInput::make('settings.wave_minutes_many_candidates')
                        ->label('Durasi gelombang, calon banyak (menit)')
                        ->helperText('Surat suara dengan > 10 calon, mis. penjaringan.')
                        ->numeric()->minValue(1)->maxValue(60)
                        ->placeholder('Bawaan: '.config('voting.defaults.wave_minutes_many_candidates')),
                    TextInput::make('settings.late_grace_seconds')
                        ->label('Toleransi telat kirim (detik)')
                        ->helperText('Konfirmasi yang tiba sesaat setelah timer habis tetap diterima.')
                        ->numeric()->minValue(0)->maxValue(60)
                        ->placeholder('Bawaan: '.config('voting.defaults.late_grace_seconds')),
                ])
                ->disabled($locked),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->when(Workspace::current(), fn (Builder $query, ElectionMode $mode): Builder => $query->where('mode', $mode)))
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->weight('bold'),
                TextColumn::make('mode')->label('Mode')->badge(),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('ballots_count')->label('Surat suara')->counts('ballots'),
                TextColumn::make('attendees_count')->label('Peserta hadir')->counts('attendees'),
                TextColumn::make('started_at')->label('Dimulai')->dateTime('d M Y H:i')->placeholder('-'),
            ])
            ->filters([
                // Pemilihan yang sudah dimulai tidak bisa dihapus (jejak audit); yang batal cukup disembunyikan.
                TernaryFilter::make('closed')
                    ->label('Pemilihan dibatalkan/diarsipkan')
                    ->placeholder('Sembunyikan')
                    ->trueLabel('Tampilkan juga')
                    ->falseLabel('Hanya yang dibatalkan/diarsipkan')
                    ->queries(
                        true: fn (Builder $query): Builder => $query,
                        false: fn (Builder $query): Builder => $query->whereIn('status', [ElectionStatus::Cancelled, ElectionStatus::Archived]),
                        blank: fn (Builder $query): Builder => $query->whereNotIn('status', [ElectionStatus::Cancelled, ElectionStatus::Archived]),
                    ),
            ])
            // Halaman pemilihan selalu bisa dibuka Super Admin (isian terkunci sesuai status).
            ->recordUrl(fn (Election $record): ?string => static::canView($record) ? static::getUrl('edit', ['record' => $record]) : null)
            ->recordActions([
                QuickStart::action('start', fn (array $arguments, ?Model $record): ?Election => $record instanceof Election ? $record : null)
                    ->label('Mulai')
                    ->size('sm'),
                Action::make('enter')
                    ->label('Masuk')
                    ->icon('heroicon-m-arrow-right')
                    ->color('primary')
                    ->requiresConfirmation(fn (Election $record): bool => Workspace::otherLiveElection($record) !== null)
                    ->modalHeading('Ada pemilihan lain yang sedang berlangsung')
                    ->modalDescription(fn (Election $record): string => 'Pemilihan "'.Workspace::otherLiveElection($record)?->name.'" sedang berlangsung. Tetap masuk ke "'.$record->name.'"? Voting di sana tetap berjalan.')
                    ->modalSubmitActionLabel('Tetap masuk')
                    ->action(fn (Election $record) => redirect()->route('workspace.election', $record->public_id)),
                QuickStart::cancelAction('cancel', fn (array $arguments, ?Model $record): ?Election => $record instanceof Election ? $record : null),
                EditAction::make()
                    ->label('Kelola')
                    ->authorize(fn (Election $record): bool => static::canView($record)),
                DeleteAction::make()
                    ->label('Hapus')
                    ->modalHeading(fn (Election $record): string => "Hapus \"{$record->name}\"?")
                    ->modalDescription('Surat suara, calon, foto calon, dan penugasan panitia pemilihan ini ikut terhapus. Tidak bisa dibatalkan.')
                    ->using(function (Election $record): bool {
                        static::deleteWithCandidatePhotos($record);

                        return true;
                    })
                    ->successNotificationTitle('Pemilihan dihapus.'),
            ]);
    }

    /**
     * Hapus pemilihan yang belum pernah dimulai beserta file foto calonnya (data lain ikut terhapus lewat relasi).
     */
    public static function deleteWithCandidatePhotos(Election $election): void
    {
        abort_unless(auth()->user()?->can('delete', $election), 403);

        $photos = app(CandidatePhotoProcessor::class);
        Candidate::query()
            ->whereHas('ballot', fn (Builder $ballot): Builder => $ballot->where('election_id', $election->id))
            ->whereNotNull('photo_key')
            ->get()
            ->each(fn (Candidate $candidate) => $photos->remove($candidate));

        $snapshot = $election->only(['name', 'mode']);
        $election->delete();
        app(AuditLogger::class)->log('election.deleted', meta: $snapshot);

        // Bila pemilihan ini yang sedang dikerjakan, kembali ke daftar pemilihan.
        if (Workspace::election() === null) {
            Workspace::chooseElection(null);
        }
    }

    public static function getRelations(): array
    {
        return [
            BallotsRelationManager::class,
            StaffRelationManager::class,
            HistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListElections::route('/'),
            'create' => CreateElection::route('/create'),
            'edit' => EditElection::route('/{record}/edit'),
        ];
    }
}
