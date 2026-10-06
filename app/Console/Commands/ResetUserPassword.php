<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Jalan darurat dari server bila Super Admin (atau akun lain) lupa password dan tidak ada
 * Super Admin lain yang bisa mereset dari panel. Password sementara tampil sekali; wajib diganti.
 */
#[Signature('pemilihan:reset-password {email : Email akun yang lupa password}')]
#[Description('Membuat password sementara baru untuk akun yang lupa password')]
class ResetUserPassword extends Command
{
    public function handle(AuditLogger $audit): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error('Akun dengan email itu tidak ditemukan.');

            return self::FAILURE;
        }

        $password = Str::password(16, symbols: false);
        $user->forceFill(['password' => $password, 'must_change_password' => true, 'is_active' => true])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        $audit->log('user.password_reset_by_console', $user, meta: ['via' => 'console'], actorType: 'system');

        $this->info("Password {$email} direset. Semua sesi akun ini dikeluarkan.");
        $this->line("Password sementara (tampil sekali): {$password}");
        $this->line('Saat login: masukkan kode dari email (bila 2FA aktif), lalu wajib ganti password.');

        return self::SUCCESS;
    }
}
