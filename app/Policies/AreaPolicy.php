<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Area;
use App\Models\User;

/**
 * Areas belong to party management. No delete: parties reference them; deactivate instead.
 */
class AreaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageParties->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageParties->value);
    }

    public function update(User $user, Area $area): bool
    {
        return $user->can(Permission::ManageParties->value);
    }
}
