<?php

namespace App\Filament\Widgets\Concerns;

use App\Models\User;

/**
 * Sales widgets are for managers and admins. The panel already admits only them; this
 * keeps the widgets closed even if panel access ever widens.
 */
trait ForMenuManagers
{
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageMenu();
    }
}
