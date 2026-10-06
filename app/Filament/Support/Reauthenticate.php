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
            ->label('Password akun Anda sendiri (yang sedang login)')
            ->helperText('Untuk memastikan tindakan penting ini memang Anda yang melakukan. Bukan password baru.')
            ->validationMessages(['current_password' => 'Password akun Anda salah. Isi password yang Anda pakai untuk login.'])
            ->password()
            ->revealable(false)
            ->required()
            ->currentPassword()
            ->autocomplete('current-password')
            ->dehydrated(false);
    }
}
