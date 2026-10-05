<?php

namespace App\Policies;

use App\Models\Election;
use App\Models\User;

/**
 * Konfigurasi pemilihan hanya oleh Super Admin. Perubahan setelah pemilihan dimulai
 * dibatasi oleh status (lihat ElectionStatus::allowsConfigurationChanges()).
 */
class ElectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Election $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Election $model): bool
    {
        return $user->isSuperAdmin() && $this->configurable($model);
    }

    public function delete(User $user, Election $model): bool
    {
        return $user->isSuperAdmin() && $this->configurable($model) && $this->deletable($model);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function configurable(Election $election): bool
    {
        return $election->status->allowsConfigurationChanges();
    }

    /**
     * Pemilihan yang sudah pernah dimulai tidak bisa dihapus (pakai Batalkan).
     */
    private function deletable(Election $election): bool
    {
        return $election->started_at === null;
    }
}
