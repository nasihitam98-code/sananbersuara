<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;

/**
 * Kolom konfirmasi password sebelum aksi sensitif (re-autentikasi, bagian 10A-A).
 */
class Reauthenticate
{
    public static function field(): TextInput
    {
        return TextInput::make('current_password')
            ->label('Konfirmasi password Anda')
            ->password()
            ->revealable(false)
            ->required()
            ->currentPassword()
            ->autocomplete('current-password')
            ->dehydrated(false);
    }
}
