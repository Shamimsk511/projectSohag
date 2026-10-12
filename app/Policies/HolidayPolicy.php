<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Holiday;
use App\Models\User;

/**
 * The holiday calendar decides which days get schedule rows, so it sits with schedule management.
 */
class HolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageSchedules->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageSchedules->value);
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        return $user->can(Permission::ManageSchedules->value);
    }
}
