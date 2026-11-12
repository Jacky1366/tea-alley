<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * Menu data is managed only by managers and admins; everyone else is denied every ability.
 */
trait AllowsMenuManagers
{
    public function viewAny(User $user): bool
    {
        return $user->canManageMenu();
    }

    public function view(User $user): bool
    {
        return $user->canManageMenu();
    }

    public function create(User $user): bool
    {
        return $user->canManageMenu();
    }

    public function update(User $user): bool
    {
        return $user->canManageMenu();
    }

    public function delete(User $user): bool
    {
        return $user->canManageMenu();
    }

    public function deleteAny(User $user): bool
    {
        return $user->canManageMenu();
    }

    public function reorder(User $user): bool
    {
        return $user->canManageMenu();
    }

    public function replicate(User $user): bool
    {
        return $user->canManageMenu();
    }

    public function restore(User $user): bool
    {
        return $user->canManageMenu();
    }

    public function restoreAny(User $user): bool
    {
        return $user->canManageMenu();
    }

    public function forceDelete(User $user): bool
    {
        return $user->canManageMenu();
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->canManageMenu();
    }
}
