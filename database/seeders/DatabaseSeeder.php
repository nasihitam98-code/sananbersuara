<?php

namespace Database\Seeders;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data dasar: peran dan daftar RT. Akun Super Admin dibuat lewat perintah
 * `php artisan pemilihan:buat-super-admin` (tidak ada password di kode).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN_RT, User::ROLE_STAFF] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $unitCount = (int) env('SEED_UNIT_COUNT', 9);

        foreach (range(1, $unitCount) as $number) {
            $code = str_pad((string) $number, 2, '0', STR_PAD_LEFT);

            Unit::query()->firstOrCreate(['code' => $code], ['name' => "RT {$code}", 'sort' => $number]);
        }
    }
}
