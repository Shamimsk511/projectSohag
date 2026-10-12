<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Party;
use App\Models\User;

/**
 * Office-side party access. Field officers' view of their assigned parties is added with assignments.
 * No delete: accounts and the ledger will reference parties; deactivate instead.
 */
class PartyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permission::ViewAllParties->value, Permission::ManageParties->value]);
    }

    public function view(User $user, Party $party): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageParties->value);
    }

    public function update(User $user, Party $party): bool
    {
        return $user->can(Permission::ManageParties->value);
    }
}
