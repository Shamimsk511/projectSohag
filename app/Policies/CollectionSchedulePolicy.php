<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CollectionSchedule;
use App\Models\User;

/**
 * Office-side schedule screens. Field officers see their own day lists in /field, not here.
 */
class CollectionSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageSchedules->value) || $user->can(Permission::ViewAllReports->value);
    }

    /**
     * Add a custom date or hand a day's rows to another officer.
     */
    public function create(User $user): bool
    {
        return $user->can(Permission::ManageSchedules->value);
    }

    public function update(User $user, CollectionSchedule $schedule): bool
    {
        return $user->can(Permission::ManageSchedules->value);
    }
}
