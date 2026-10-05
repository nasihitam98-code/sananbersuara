<?php

namespace App\Policies;

use App\Models\ElectionStaff;
use App\Models\User;

/**
 * Konfigurasi pemilihan hanya oleh Super Admin. Perubahan setelah pemilihan dimulai
 * dibatasi oleh status (lihat ElectionStatus::allowsConfigurationChanges()).
 */
class ElectionStaffPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, ElectionStaff $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, ElectionStaff $model): bool
    {
        return $user->isSuperAdmin() && $this->configurable($model);
    }

    public function delete(User $user, ElectionStaff $model): bool
    {
        return $user->isSuperAdmin() && $this->configurable($model) && $this->deletable($model);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Penugasan staf boleh diubah selama pemilihan belum selesai.
     */
    private function configurable(ElectionStaff $staff): bool
    {
        return $staff->election->status->allowsConfigurationChanges() || $staff->election->status->isLive();
    }

    private function deletable(ElectionStaff $staff): bool
    {
        return true;
    }
}
