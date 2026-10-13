<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\ScheduleStatus;
use App\Models\CollectionSchedule;
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

    /**
     * Also the officer covering a day: any row of the party on their list, even without the assignment.
     */
    public function viewPhoto(User $user, Party $party): bool
    {
        return $this->view($user, $party)
            || CollectionSchedule::query()->where('party_id', $party->id)->where('field_officer_id', $user->id)->exists();
    }

    /**
     * "Collect all" for a client: the officer has an open row of that client on today's field list.
     */
    public function collect(User $user, Party $party): bool
    {
        return $user->can(Permission::RecordCollections->value)
            && CollectionSchedule::query()
                ->onDayListOf($user, today(config('app.display_timezone')))
                ->where('party_id', $party->id)
                ->whereIn('status', ScheduleStatus::collectable())
                ->exists();
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageParties->value);
    }

    public function update(User $user, Party $party): bool
    {
        return $user->can(Permission::ManageParties->value);
    }

    /**
     * Issue, print, share or reset the member's portal link and PIN.
     */
    public function managePortal(User $user, Party $party): bool
    {
        return $user->can(Permission::ManageParties->value);
    }

    public function assign(User $user, Party $party): bool
    {
        return $user->can(Permission::AssignParties->value);
    }
}
