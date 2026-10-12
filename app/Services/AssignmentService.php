<?php

namespace App\Services;

use App\Models\Party;
use App\Models\PartyAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Only writer of party_assignments. Keeps one active assignment per party and closes, never deletes,
 * the previous one so the history of responsibility stays intact.
 */
class AssignmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Make $officer responsible for $party from today, ending any current assignment.
     * Assigning the officer who already holds the party changes nothing.
     */
    public function assign(Party $party, User $officer, User $actor): PartyAssignment
    {
        return DB::transaction(function () use ($party, $officer, $actor): PartyAssignment {
            $current = $this->lockedActiveAssignment($party);

            if ($current?->field_officer_id === $officer->id) {
                return $current;
            }

            $this->close($current, $actor);

            $assignment = PartyAssignment::create([
                'party_id' => $party->id,
                'field_officer_id' => $officer->id,
                'assigned_from' => $this->today(),
                'assigned_by' => $actor->id,
            ]);

            $this->audit->created('assignment.assigned', $assignment, $actor);

            return $assignment;
        });
    }

    /**
     * End the party's current assignment, leaving it unassigned. No-op when it has none.
     */
    public function end(Party $party, User $actor): void
    {
        DB::transaction(function () use ($party, $actor): void {
            $this->close($this->lockedActiveAssignment($party), $actor);
        });
    }

    /**
     * Locks the party row first so concurrent (re)assignments of one party run one after another.
     */
    private function lockedActiveAssignment(Party $party): ?PartyAssignment
    {
        Party::query()->whereKey($party->id)->lockForUpdate()->firstOrFail();

        return $party->activeAssignment()->first();
    }

    private function close(?PartyAssignment $assignment, User $actor): void
    {
        if ($assignment === null) {
            return;
        }

        $assignment->update(['assigned_until' => $this->today(), 'ended_by' => $actor->id]);
        $this->audit->updated('assignment.ended', $assignment, $actor);
    }

    private function today(): string
    {
        return today(config('app.display_timezone'))->toDateString();
    }
}
