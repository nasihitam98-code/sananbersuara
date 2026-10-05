<?php

namespace App\Policies;

use App\Models\Candidate;
use App\Models\User;

/**
 * Konfigurasi pemilihan hanya oleh Super Admin. Perubahan setelah pemilihan dimulai
 * dibatasi oleh status (lihat ElectionStatus::allowsConfigurationChanges()).
 */
class CandidatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Candidate $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Candidate $model): bool
    {
        return $user->isSuperAdmin() && $this->configurable($model);
    }

    public function delete(User $user, Candidate $model): bool
    {
        return $user->isSuperAdmin() && $this->configurable($model) && $this->deletable($model);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function configurable(Candidate $candidate): bool
    {
        return $candidate->ballot->election->status->allowsConfigurationChanges();
    }

    private function deletable(Candidate $candidate): bool
    {
        return ! $candidate->ballot->election->votes()->where('candidate_id', $candidate->id)->exists();
    }
}
