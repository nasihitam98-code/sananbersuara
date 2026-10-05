<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Audit log hanya dibaca Super Admin (K30). Tidak ada yang bisa membuat, mengubah, atau menghapus lewat aplikasi.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
