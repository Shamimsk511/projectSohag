<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Who may close a business date. No update/delete: a closed day is never reopened.
 */
class DailyClosingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::CloseDays->value) || $user->can(Permission::ViewAllReports->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CloseDays->value);
    }
}
