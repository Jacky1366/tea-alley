<?php

namespace App\Policies;

use App\Models\User;

/**
 * Managers and admins can view and edit store hours. The 7 weekday rows are fixed
 * (see the insert_default_store_hours migration), so nobody may create or delete them.
 */
class StoreHourPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageMenu();
    }

    public function view(User $user): bool
    {
        return $user->canManageMenu();
    }

    public function update(User $user): bool
    {
        return $user->canManageMenu();
    }

    public function create(User $user): bool
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
