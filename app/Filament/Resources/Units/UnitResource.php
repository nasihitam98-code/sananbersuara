<?php

namespace App\Filament\Resources\Units;

use App\Filament\Resources\Units\Pages\ManageUnits;
use App\Models\Unit;
use App\Services\AuditLogger;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;
use UnitEnum;

/**
 * Daftar RT (Super Admin): tambah, ubah nama/urutan, dan hapus RT yang belum dipakai data apa pun.
 */
class UnitResource extends Resource
{
    protected static ?string $model = Unit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static string|UnitEnum|null $navigationGroup = 'Lainnya';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'RT';

    protected static ?string $pluralModelLabel = 'Daftar RT';

    protected static ?string $slug = 'rt';

    /**
     * Nomor RT selalu minimal dua angka: "3" dan "003" menjadi "03".
     */
    public static function normalizeCode(string $code): string
    {
        return str_pad(ltrim($code, '0') ?: '0', 2, '0', STR_PAD_LEFT);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                TextInput::make('code')
                    ->label('Nomor RT')
                    ->helperText('Dua angka, mis. 10.')
                    ->required()
                    ->regex('/^\d{1,3}$/')
                    // Satu bentuk kode ("3" → "03") agar link RT di halaman publik (#rt-03) dan impor pemilih selalu cocok.
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? static::normalizeCode($state) : $state)
                    ->maxLength(3)
                    ->rule(fn (?Unit $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $taken = filled($value) && Unit::query()
                            ->where('code', static::normalizeCode((string) $value))
                            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                            ->exists();

                        if ($taken) {
                            $fail('Nomor RT ini sudah ada.');
                        }
                    })
                    ->validationMessages(['regex' => 'Isi dengan angka saja, mis. 10.'])
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                        if (filled($state) && preg_match('/^\d{1,3}$/', $state)) {
                            $state = static::normalizeCode($state);
                            $set('code', $state);
                        }

                        if (filled($state) && blank($get('name'))) {
                            $set('name', 'RT '.str_pad($state, 2, '0', STR_PAD_LEFT));
                        }
                    }),
                TextInput::make('name')
                    ->label('Nama tampilan')
                    ->helperText('Mis. RT 10. Tampil di semua halaman.')
                    ->required()
                    ->maxLength(50),
                TextInput::make('sort')
                    ->label('Urutan')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(999)
                    ->default(fn (): int => (int) Unit::query()->max('sort') + 1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->paginated(false)
            ->columns([
                TextColumn::make('sort')->label('Urutan')->sortable(),
                TextColumn::make('code')->label('Nomor')->fontFamily('mono'),
                TextColumn::make('name')->label('Nama')->weight('bold')->searchable(),
                TextColumn::make('voters_count')->label('Pemilih terdaftar')->counts('voters'),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(fn (Unit $record) => app(AuditLogger::class)->log('unit.updated', $record, meta: $record->only(['code', 'name', 'sort']))),
                DeleteAction::make()
                    ->modalHeading(fn (Unit $record): string => "Hapus {$record->name}?")
                    ->modalDescription('RT hanya bisa dihapus bila belum dipakai pemilih, calon, akun, perangkat, atau data pemilihan apa pun.')
                    ->modalSubmitActionLabel('Ya, hapus')
                    ->action(function (Unit $record, DeleteAction $action): void {
                        $snapshot = $record->only(['code', 'name']);

                        try {
                            $record->delete();
                        } catch (QueryException) {
                            Notification::make()
                                ->title("{$record->name} tidak bisa dihapus karena sudah dipakai data lain (pemilih, calon, akun, atau pemilihan).")
                                ->danger()
                                ->persistent()
                                ->send();
                            $action->cancel();

                            return;
                        }

                        app(AuditLogger::class)->log('unit.deleted', meta: $snapshot);
                        $action->success();
                    })
                    ->successNotificationTitle('RT dihapus.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUnits::route('/'),
        ];
    }
}
