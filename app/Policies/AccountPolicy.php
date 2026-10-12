<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\User;

/**
 * Whoever may see the party may see its accounts. No delete: the ledger references accounts.
 */
class AccountPolicy
{
    public function view(User $user, Account $account): bool
    {
        return $user->can('view', $account->party);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageAccounts->value);
    }

    /**
     * Adjust the contract amount or close.
     */
    public function update(User $user, Account $account): bool
    {
        return $user->can(Permission::ManageAccounts->value);
    }
}
