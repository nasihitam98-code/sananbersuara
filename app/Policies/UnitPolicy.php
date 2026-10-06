<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;

/**
 * Daftar RT hanya diatur Super Admin. Penghapusan RT yang sudah dipakai data lain
 * ditolak oleh foreign key (restrictOnDelete) dan dijelaskan di halaman.
 */
class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Unit $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Unit $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Unit $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
