<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Staff accounts are managed by admin only (users.manage has no non-admin holder).
 * There is no delete: accounts are deactivated so ledger history keeps its owner.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageUsers->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageUsers->value);
    }

    public function update(User $user, User $model): bool
    {
        return $user->can(Permission::ManageUsers->value);
    }
}
