<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Membuat akun Super Admin dengan password sementara acak (tampil sekali di terminal).
 * Pengguna wajib mengganti password dan memasang 2FA saat login pertama.
 */
#[Signature('pemilihan:buat-super-admin {email : Email untuk login} {name : Nama lengkap}')]
#[Description('Membuat akun Super Admin dengan password sementara')]
class CreateSuperAdmin extends Command
{
    public function handle(AuditLogger $audit): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Email tidak valid.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error('Email sudah terdaftar.');

            return self::FAILURE;
        }

        Role::findOrCreate(User::ROLE_SUPER_ADMIN, 'web');

        $password = Str::password(16, symbols: false);

        $user = new User(['name' => (string) $this->argument('name'), 'email' => $email, 'password' => $password]);
        $user->forceFill(['is_active' => true, 'must_change_password' => true])->save();
        $user->assignRole(User::ROLE_SUPER_ADMIN);

        $audit->log('user.created', $user, meta: ['role' => User::ROLE_SUPER_ADMIN, 'via' => 'console'], actorType: 'system');

        $this->info("Super Admin dibuat: {$email}");
        $this->line("Password sementara (tampil sekali): {$password}");
        $this->line('Saat login pertama: pasang 2FA (aplikasi authenticator) lalu ganti password.');

        return self::SUCCESS;
    }
}
