<?php

namespace App\Filament\Resources\Elections;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Filament\Resources\Elections\Pages\CreateElection;
use App\Filament\Resources\Elections\Pages\EditElection;
use App\Filament\Resources\Elections\Pages\ListElections;
use App\Filament\Resources\Elections\RelationManagers\BallotsRelationManager;
use App\Filament\Resources\Elections\RelationManagers\StaffRelationManager;
use App\Models\Election;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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

    public static function form(Schema $schema): Schema
    {
        $locked = fn (?Election $record): bool => $record !== null && ! $record->status->allowsConfigurationChanges();

        return $schema
            ->components([
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
                            ->default(ElectionMode::Dadakan)
                            ->helperText('Dadakan: QR + nama + PIN (rapat). Resmi: Meja Izin + laptop bilik per RT.')
                            ->required()
                            ->live()
                            ->disabled(fn (?Election $record): bool => $record !== null && $record->status !== ElectionStatus::Draft),
                    ]),
                Section::make('Pengaturan Mode Resmi')
                    ->description('Bisa diubah selama status Draf atau Siap.')
                    ->columns(3)
                    ->visible(fn (Get $get): bool => in_array($get('mode'), [ElectionMode::Resmi, ElectionMode::Resmi->value], true))
                    ->schema([
                        TextInput::make('settings.max_booths_per_unit')
                            ->label('Jumlah bilik per RT')
                            ->numeric()->minValue(1)->maxValue(10)
                            ->default(config('voting.defaults.max_booths_per_unit'))
                            ->required(),
                        TextInput::make('settings.permit_expiry_minutes')
                            ->label('Izin hangus jika bilik tidak disentuh (menit)')
                            ->numeric()->minValue(1)->maxValue(30)
                            ->default(config('voting.defaults.permit_expiry_minutes'))
                            ->required(),
                        TextInput::make('settings.booth_idle_minutes')
                            ->label('Sesi bilik berakhir jika diam (menit)')
                            ->numeric()->minValue(1)->maxValue(30)
                            ->default(config('voting.defaults.booth_idle_minutes'))
                            ->required(),
                        TextInput::make('settings.booth_token_minutes')
                            ->label('Masa berlaku token bilik (menit)')
                            ->numeric()->minValue(1)->maxValue(60)
                            ->default(config('voting.defaults.booth_token_minutes'))
                            ->required(),
                        TextInput::make('settings.desk_token_hours')
                            ->label('Masa berlaku token meja (jam)')
                            ->numeric()->minValue(1)->maxValue(72)
                            ->default(config('voting.defaults.desk_token_hours'))
                            ->required(),
                    ])
                    ->disabled($locked),
                Section::make('Pengaturan Mode Dadakan')
                    ->description('Bisa diubah selama status Draf atau Siap.')
                    ->columns(3)
                    ->visible(fn (Get $get): bool => ! in_array($get('mode'), [ElectionMode::Resmi, ElectionMode::Resmi->value], true))
                    ->schema([
                        TextInput::make('settings.wave_minutes')
                            ->label('Durasi gelombang (menit)')
                            ->helperText('Surat suara dengan ≤ 10 calon.')
                            ->numeric()->minValue(1)->maxValue(60)
                            ->default(config('voting.defaults.wave_minutes'))
                            ->required(),
                        TextInput::make('settings.wave_minutes_many_candidates')
                            ->label('Durasi gelombang, calon banyak (menit)')
                            ->helperText('Surat suara dengan > 10 calon, mis. penjaringan.')
                            ->numeric()->minValue(1)->maxValue(60)
                            ->default(config('voting.defaults.wave_minutes_many_candidates'))
                            ->required(),
                        TextInput::make('settings.late_grace_seconds')
                            ->label('Toleransi telat kirim (detik)')
                            ->helperText('Konfirmasi yang tiba sesaat setelah timer habis tetap diterima.')
                            ->numeric()->minValue(0)->maxValue(60)
                            ->default(config('voting.defaults.late_grace_seconds'))
                            ->required(),
                    ])
                    ->disabled($locked),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->weight('bold'),
                TextColumn::make('mode')->label('Mode')->badge(),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('ballots_count')->label('Surat suara')->counts('ballots'),
                TextColumn::make('attendees_count')->label('Peserta hadir')->counts('attendees'),
                TextColumn::make('started_at')->label('Dimulai')->dateTime('d M Y H:i')->placeholder('-'),
            ])
            ->recordActions([
                EditAction::make()->label('Kelola'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            BallotsRelationManager::class,
            StaffRelationManager::class,
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
