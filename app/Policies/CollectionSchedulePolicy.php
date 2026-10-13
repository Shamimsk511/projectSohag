<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CollectionSchedule;
use App\Models\User;

/**
 * Office-side schedule screens, plus collecting on a row from the field officer's day list.
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

    /**
     * Record a collection on the row: only the officer the row is assigned to.
     */
    public function collect(User $user, CollectionSchedule $schedule): bool
    {
        return $user->can(Permission::RecordCollections->value) && $schedule->field_officer_id === $user->id;
    }

    /**
     * Send the member a WhatsApp reminder for this row: its officer, or office staff who see schedules.
     */
    public function remind(User $user, CollectionSchedule $schedule): bool
    {
        return $this->collect($user, $schedule) || $this->viewAny($user);
    }
}
