<?php

namespace App\Policies;

use App\Models\User;

/**
 * Hanya Super Admin yang mengelola akun. Tidak ada registrasi publik dan tidak ada hapus akun
 * (akun dinonaktifkan agar jejak audit tetap utuh).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
