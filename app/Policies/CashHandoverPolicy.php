<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Office staff who take cash from field officers record handovers. No update/delete: rows are immutable.
 */
class CashHandoverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ReceiveCash->value) || $user->can(Permission::ViewAllReports->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ReceiveCash->value);
    }
}
