<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\OfficeHead;
use App\Models\User;

/**
 * Office heads belong to office income/expense posting. No delete: ledger rows reference them.
 */
class OfficeHeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PostOfficeEntries->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PostOfficeEntries->value);
    }

    public function update(User $user, OfficeHead $head): bool
    {
        return $user->can(Permission::PostOfficeEntries->value);
    }
}
