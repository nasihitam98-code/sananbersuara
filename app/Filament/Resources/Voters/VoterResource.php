<?php

namespace App\Filament\Resources\Voters;

use App\Enums\ElectionMode;
use App\Enums\EmergencyAddReason;
use App\Filament\Pages\ImportVoters;
use App\Filament\Resources\Voters\Pages\CreateVoter;
use App\Filament\Resources\Voters\Pages\EditVoter;
use App\Filament\Resources\Voters\Pages\ListVoters;
use App\Filament\Support\Workspace;
use App\Models\Unit;
use App\Models\User;
use App\Models\Voter;
use App\Services\Voters\VoterRegistry;
use App\Services\Voting\VotingException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Data pemilih Mode Resmi. Query otomatis dibatasi RT untuk Admin RT (bagian 10A-C).
 */
class VoterResource extends Resource
{
    protected static ?string $model = Voter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Persiapan';

    protected static ?string $modelLabel = 'pemilih';

    protected static ?string $pluralModelLabel = 'Data Pemilih';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 4;

    public static function shouldRegisterNavigation(): bool
    {
        return Workspace::shows(ElectionMode::Resmi);
    }

    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with('unit')
            ->when(! $user->isSuperAdmin(), fn (Builder $query) => $query->where('unit_id', $user->unit_id ?? 0));
    }

    public static function form(Schema $schema): Schema
    {
        /** @var User $user */
        $user = auth()->user();
        $liveElection = app(VoterRegistry::class)->liveResmiElection();

        return $schema
            ->components([
                // Yang wajib hanya nama (dan RT untuk Super Admin; Admin RT otomatis RT sendiri).
                Section::make('Data pemilih')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nama lengkap')->required()->minLength(3)->maxLength(120),
                        Select::make('unit_id')
                            ->label('RT')
                            ->options(fn (): array => Unit::query()->orderBy('sort')->pluck('name', 'id')->all())
                            // Dari tombol "Tambah pemilih RT 03" di tab RT: sudah terisi.
                            ->default(fn (): ?int => filled(request()->query('rt')) ? Unit::query()->where('code', (string) request()->query('rt'))->value('id') : null)
                            ->required()
                            ->visible($user->isSuperAdmin())
                            ->disabledOn('edit')
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Koreksi RT dilakukan lewat tombol "Koreksi RT" (Super Admin, dengan alasan).' : null),
                        Checkbox::make('confirm_different_person')
                            ->label('Saya sudah memastikan ini orang yang berbeda (jika muncul peringatan nama sama)')
                            ->columnSpanFull(),
                    ]),
                // Hanya di Ubah: form Buat cukup nama + RT (keputusan pemilik 2026-10-22). Dilengkapi bila perlu, mis. nama kembar.
                Section::make('Data tambahan (opsional)')
                    ->description('Boleh dikosongkan. Alamat membantu membedakan nama kembar di Meja Izin.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->schema([
                        TextInput::make('address')->label('Alamat singkat / no. rumah')->maxLength(190),
                        Select::make('gender')->label('Jenis kelamin')->options(['L' => 'Laki-laki', 'P' => 'Perempuan']),
                        DatePicker::make('birth_date')->label('Tanggal lahir')->native(false)->displayFormat('d-m-Y')->maxDate(now()),
                        TextInput::make('nik')
                            ->label('NIK (opsional)')
                            ->helperText(fn (?Voter $record): string => 'Tidak disimpan penuh, hanya 4 digit terakhir dan sidik acak untuk cek duplikat.'.($record?->nik_last4 ? ' Saat ini: '.$record->maskedNik().'. Kosongkan jika tidak diubah.' : ''))
                            ->length(16)
                            ->numeric()
                            ->autocomplete(false),
                        TextInput::make('phone')->label('No. HP (opsional)')->tel()->maxLength(15),
                    ]),
                // Hanya saat membuat DAN ada pemilihan Resmi berlangsung (visibleOn() sendirian menimpa syarat ini).
                Section::make('Tambah darurat saat pemilihan berlangsung')
                    ->columnSpanFull()
                    ->description('Daftar pemilih sedang dibekukan karena pemilihan berlangsung. Penambahan dicatat khusus di laporan dan dilaporkan ke Super Admin.')
                    ->visible(fn (string $operation): bool => $operation === 'create' && $liveElection !== null)
                    ->schema([
                        Select::make('emergency_reason')->label('Alasan')->options(EmergencyAddReason::class)->required($liveElection !== null),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        /** @var User $user */
        $user = auth()->user();

        return $table
            // Urut per RT lalu No. urut (seperti daftar absen RT), agar tabel rapi.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderBy(Unit::query()->select('sort')->whereColumn('units.id', 'voters.unit_id'))
                ->orderBy('rt_number')
                ->orderBy('name'))
            ->columns([
                // No. urut per RT; kotak Cari menerima nomor (mis. "75").
                TextColumn::make('rt_number')
                    ->label('No.')
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => ctype_digit(trim($search))
                        ? $query->where('rt_number', (int) trim($search))
                        : $query->whereRaw('1 = 0'))
                    ->alignEnd()
                    ->width('4rem'),
                TextColumn::make('voter_number')->label('ID')->searchable()->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')->label('Nama')->searchable(query: fn (Builder $query, string $search): Builder => $query->where('name_search', 'like', '%'.addcslashes(mb_strtolower($search), '%_\\').'%'))->weight('bold'),
                TextColumn::make('unit.name')->label('RT')->sortable(),
                // Form Buat cukup nama + RT, jadi alamat/NIK biasanya kosong: disembunyikan, bisa dimunculkan dari ikon kolom.
                TextColumn::make('address')->label('Alamat')->limit(30)->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('nik_last4')->label('NIK')->formatStateUsing(fn (Voter $record): string => $record->maskedNik())->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
                // Hanya yang ditambah saat pemilihan berlangsung diberi label; lainnya kosong (bukan ❌ merah yang terlihat seperti galat).
                TextColumn::make('emergency')
                    ->label('Darurat')
                    ->state(fn (Voter $record): ?string => $record->added_during_live ? 'Darurat' : null)
                    ->badge()
                    ->color('warning')
                    ->tooltip(fn (Voter $record): ?string => $record->added_during_live ? 'Ditambah saat pemilihan berlangsung' : null),
            ])
            ->filters([
                // Saringan RT diganti tab per RT di atas tabel (ListVoters::getTabs).
                TernaryFilter::make('is_active')->label('Status aktif'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('deactivate')
                    ->label('Nonaktifkan')
                    ->color('warning')
                    ->visible(fn (Voter $record): bool => $record->is_active)
                    ->schema([Textarea::make('reason')->label('Alasan')->required()->maxLength(190)])
                    ->action(fn (Voter $record, array $data) => static::run(fn () => app(VoterRegistry::class)->deactivate($record, $data['reason'], auth()->user()), 'Pemilih dinonaktifkan.')),
                Action::make('reactivate')
                    ->label('Aktifkan')
                    ->color('success')
                    ->visible(fn (Voter $record): bool => ! $record->is_active)
                    ->requiresConfirmation()
                    ->action(fn (Voter $record) => static::run(fn () => app(VoterRegistry::class)->reactivate($record, auth()->user()), 'Pemilih diaktifkan.')),
                Action::make('moveUnit')
                    ->label('Koreksi RT')
                    ->color('gray')
                    ->visible(fn (): bool => auth()->user()->isSuperAdmin())
                    ->schema([
                        Select::make('unit_id')->label('RT yang benar')->options(fn (): array => Unit::query()->orderBy('sort')->pluck('name', 'id')->all())->required(),
                        Textarea::make('reason')->label('Alasan koreksi')->required()->maxLength(500),
                    ])
                    ->action(fn (Voter $record, array $data) => static::run(fn () => app(VoterRegistry::class)->moveUnit($record, (int) $data['unit_id'], $data['reason'], auth()->user()), 'RT dikoreksi.')),
                Action::make('delete')
                    ->label('Hapus')
                    ->color('danger')
                    ->visible(fn (Voter $record): bool => auth()->user()->can('delete', $record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Voter $record): string => "Hapus {$record->name} ({$record->unit?->name})?")
                    ->modalDescription('Data pemilih ini dihapus permanen dan tercatat di audit. Bila hanya pindah/meninggal/tidak memenuhi syarat, lebih baik "Nonaktifkan".')
                    ->modalSubmitActionLabel('Ya, hapus')
                    ->action(fn (Voter $record) => static::run(fn () => app(VoterRegistry::class)->delete($record, auth()->user()), 'Pemilih dihapus.')),
            ])
            // Centang beberapa (atau semua) baris lalu hapus sekaligus; aturan hapus tetap diperiksa per pemilih.
            ->toolbarActions([
                BulkAction::make('deleteSelected')
                    ->label('Hapus yang dicentang')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Collection $records): string => "Hapus {$records->count()} pemilih?")
                    ->modalDescription('Data pemilih yang dicentang dihapus permanen dan tercatat di audit. Pemilih yang sudah pernah ikut memilih tidak bisa dihapus (dilewati); nonaktifkan saja.')
                    ->modalSubmitActionLabel('Ya, hapus')
                    ->action(function (Collection $records): void {
                        $deleted = 0;

                        foreach ($records as $record) {
                            if (! auth()->user()->can('delete', $record)) {
                                continue;
                            }

                            try {
                                app(VoterRegistry::class)->delete($record, auth()->user());
                                $deleted++;
                            } catch (VotingException) {
                                // Dilewati: punya riwayat pemilihan.
                            }
                        }

                        $skipped = $records->count() - $deleted;
                        Notification::make()
                            ->title("{$deleted} pemilih dihapus.".($skipped > 0 ? " {$skipped} dilewati (sudah pernah ikut memilih atau bukan RT Anda)." : ''))
                            ->status($skipped > 0 ? 'warning' : 'success')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->headerActions([
                Action::make('import')
                    ->label('Import Excel/CSV')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->url(fn (): string => ImportVoters::getUrl()),
                Action::make('template')
                    ->label('Unduh template')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (): string => route('voters.template')),
            ]);
    }

    public static function run(callable $callback, string $success): void
    {
        try {
            $callback();
        } catch (VotingException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVoters::route('/'),
            'create' => CreateVoter::route('/create'),
            'edit' => EditVoter::route('/{record}/edit'),
        ];
    }
}
