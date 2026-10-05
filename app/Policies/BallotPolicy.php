<?php

namespace App\Policies;

use App\Models\Ballot;
use App\Models\User;

/**
 * Konfigurasi pemilihan hanya oleh Super Admin. Perubahan setelah pemilihan dimulai
 * dibatasi oleh status (lihat ElectionStatus::allowsConfigurationChanges()).
 */
class BallotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Ballot $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Ballot $model): bool
    {
        return $user->isSuperAdmin() && $this->configurable($model);
    }

    public function delete(User $user, Ballot $model): bool
    {
        return $user->isSuperAdmin() && $this->configurable($model) && $this->deletable($model);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function configurable(Ballot $ballot): bool
    {
        return $ballot->election->status->allowsConfigurationChanges();
    }

    private function deletable(Ballot $ballot): bool
    {
        return ! $ballot->election->votes()->where('ballot_id', $ballot->id)->exists();
    }
}
