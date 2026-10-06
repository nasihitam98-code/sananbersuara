<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Voter;

/**
 * Super Admin: semua RT. Admin RT dengan izin "kelola pemilih": hanya RT sendiri.
 * Akses ke pemilih RT lain lewat URL/ID ditolak (bagian 4.2 dan 10A-C).
 */
class VoterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || ($user->isAdminRt() && $user->hasPermissionTo(User::PERMISSION_MANAGE_VOTERS));
    }

    public function view(User $user, Voter $voter): bool
    {
        return $user->canManageVotersOf($voter->unit_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Voter $voter): bool
    {
        return $user->canManageVotersOf($voter->unit_id);
    }

    public function delete(User $user, Voter $voter): bool
    {
        return $user->canManageVotersOf($voter->unit_id) && ! $voter->hasVotingHistory() && ! $voter->ballotEntries()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
