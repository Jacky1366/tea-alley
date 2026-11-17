<?php

namespace App\Policies;

use App\Models\User;

/**
 * T2-8: only admins can read the audit log, and nobody can create, change, or delete an
 * entry through the app (AuditObserver writes them; AuditLog refuses updates and deletes).
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewAuditLog();
    }

    public function view(User $user): bool
    {
        return $user->canViewAuditLog();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user): bool
    {
        return false;
    }

    public function delete(User $user): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
