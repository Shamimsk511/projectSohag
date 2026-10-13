<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Opening balances are an admin-only go-live step. No update/delete: the row is immutable.
 */
class OpeningBalancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::SetOpeningBalances->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::SetOpeningBalances->value);
    }
}
