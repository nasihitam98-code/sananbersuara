<?php

namespace App\Filament\Resources\Voters;

use App\Enums\EmergencyAddReason;
use App\Filament\Pages\ImportVoters;
use App\Filament\Resources\Voters\Pages\CreateVoter;
use App\Filament\Resources\Voters\Pages\EditVoter;
use App\Filament\Resources\Voters\Pages\ListVoters;
use App\Models\Unit;
use App\Models\User;
use App\Models\Voter;
use App\Services\Voters\VoterRegistry;
use App\Services\Voting\VotingException;
use BackedEnum;
use Filament\Actions\Action;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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

    protected static ?int $navigationSort = 3;

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
                Section::make('Data pemilih')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nama lengkap')->required()->minLength(3)->maxLength(120),
                        Select::make('unit_id')
                            ->label('RT')
                            ->options(fn (): array => Unit::query()->orderBy('sort')->pluck('name', 'id')->all())
                            ->required()
                            ->visible($user->isSuperAdmin())
                            ->disabledOn('edit')
                            ->helperText('Koreksi RT dilakukan lewat tombol "Koreksi RT" (Super Admin, dengan alasan).'),
                        TextInput::make('address')->label('Alamat singkat / no. rumah')->required()->maxLength(190)
                            ->helperText('Pembeda untuk nama kembar di Meja Izin.'),
                        Select::make('gender')->label('Jenis kelamin')->options(['L' => 'Laki-laki', 'P' => 'Perempuan']),
                        DatePicker::make('birth_date')->label('Tanggal lahir')->native(false)->displayFormat('d-m-Y')->maxDate(now()),
                        TextInput::make('nik')
                            ->label('NIK (opsional)')
                            ->helperText(fn (?Voter $record): string => 'Tidak disimpan penuh, hanya 4 digit terakhir dan sidik acak untuk cek duplikat.'.($record?->nik_last4 ? ' Saat ini: '.$record->maskedNik().'. Kosongkan jika tidak diubah.' : ''))
                            ->length(16)
                            ->numeric()
                            ->autocomplete(false),
                        TextInput::make('phone')->label('No. HP (opsional)')->tel()->maxLength(15),
                        Checkbox::make('confirm_different_person')
                            ->label('Saya sudah memastikan ini orang yang berbeda (jika muncul peringatan nama sama)')
                            ->columnSpanFull(),
                    ]),
                Section::make('Tambah darurat saat pemilihan berlangsung')
                    ->description('Daftar pemilih sedang dibekukan karena pemilihan berlangsung. Penambahan dicatat khusus di laporan dan dilaporkan ke Super Admin.')
                    ->visible($liveElection !== null)
                    ->visibleOn('create')
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
            ->defaultSort('name')
            ->columns([
                TextColumn::make('voter_number')->label('ID')->searchable()->fontFamily('mono'),
                TextColumn::make('name')->label('Nama')->searchable(query: fn (Builder $query, string $search): Builder => $query->where('name_search', 'like', '%'.addcslashes(mb_strtolower($search), '%_\\').'%'))->weight('bold'),
                TextColumn::make('unit.name')->label('RT')->sortable(),
                TextColumn::make('address')->label('Alamat')->limit(30),
                TextColumn::make('nik_last4')->label('NIK')->formatStateUsing(fn (Voter $record): string => $record->maskedNik())->placeholder('-'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
                IconColumn::make('added_during_live')->label('Darurat')->boolean()->trueColor('warning'),
            ])
            ->filters([
                SelectFilter::make('unit_id')->label('RT')
                    ->options(fn (): array => Unit::query()->orderBy('sort')->pluck('name', 'id')->all())
                    ->visible($user->isSuperAdmin()),
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
                    ->modalDescription('Hanya untuk pemilih yang belum pernah punya riwayat pemilihan.')
                    ->action(fn (Voter $record) => static::run(fn () => app(VoterRegistry::class)->delete($record, auth()->user()), 'Pemilih dihapus.')),
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
