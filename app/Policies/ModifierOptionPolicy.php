<?php

namespace App\Policies;

use App\Policies\Concerns\AllowsMenuManagers;

/**
 * Options are edited through the ModifierGroup relation manager, which authorizes against this policy.
 */
class ModifierOptionPolicy
{
    use AllowsMenuManagers;
}
