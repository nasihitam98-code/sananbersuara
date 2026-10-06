<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Support\PasswordHint;
use App\Filament\Support\Workspace;
use App\Models\Unit;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Persiapan';

    protected static ?int $navigationSort = 4;

    public static function shouldRegisterNavigation(): bool
    {
        return Workspace::shows();
    }

    protected static ?string $modelLabel = 'akun';

    protected static ?string $pluralModelLabel = 'Akun';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        return [
            User::ROLE_SUPER_ADMIN => 'Super Admin',
            User::ROLE_STAFF => 'Staf pemilihan (Panitia / Petugas Pintu, ditugaskan per pemilihan)',
            User::ROLE_ADMIN_RT => 'Admin RT (Mode Resmi, satu akun = satu RT)',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function permissionOptions(): array
    {
        return [
            User::PERMISSION_MANAGE_VOTERS => 'Kelola data pemilih RT',
            User::PERMISSION_DESK => 'Petugas Meja Izin (hanya dari laptop meja RT-nya)',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Akun')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nama')->required()->maxLength(120),
                        TextInput::make('email')->label('Email (untuk login)')->email()->required()->maxLength(190)->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->label('Password sementara')
                            ->helperText(PasswordHint::TEXT.'. Pengguna wajib menggantinya saat login pertama.')
                            ->password()
                            ->revealable()
                            ->rule(Password::default())
                            ->required()
                            ->visibleOn('create'),
                        Select::make('role')
                            ->label('Peran')
                            ->options(static::roleOptions())
                            ->live()
                            ->required(),
                        Select::make('unit_id')
                            ->label('RT')
                            ->options(fn (): array => Unit::query()->orderBy('sort')->pluck('name', 'id')->all())
                            ->visible(fn (Get $get): bool => $get('role') === User::ROLE_ADMIN_RT)
                            ->required(fn (Get $get): bool => $get('role') === User::ROLE_ADMIN_RT),
                        CheckboxList::make('permissions')
                            ->label('Izin Admin RT')
                            ->options(static::permissionOptions())
                            ->visible(fn (Get $get): bool => $get('role') === User::ROLE_ADMIN_RT)
                            ->helperText('Boleh keduanya (K07). Petugas meja relawan cukup diberi izin Petugas Meja saja.'),
                        Toggle::make('is_active')->label('Aktif')->default(true)->visibleOn('edit'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->weight('bold'),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('roles.name')->label('Peran')->badge()
                    ->formatStateUsing(fn (string $state): string => static::roleOptions()[$state] ?? $state),
                TextColumn::make('unit.name')->label('RT')->placeholder('-'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
                IconColumn::make('has_email_authentication')->label('2FA email')->boolean(),
                TextColumn::make('last_login_at')->label('Login terakhir')->dateTime('d M Y H:i')->placeholder('-'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
