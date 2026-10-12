<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Party;
use App\Models\User;

/**
 * Office staff see parties by permission; a field officer sees only parties currently assigned to them.
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
        return $this->viewAny($user)
            || $party->activeAssignment()->where('field_officer_id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageParties->value);
    }

    public function update(User $user, Party $party): bool
    {
        return $user->can(Permission::ManageParties->value);
    }

    public function assign(User $user, Party $party): bool
    {
        return $user->can(Permission::AssignParties->value);
    }
}
