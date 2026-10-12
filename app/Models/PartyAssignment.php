<?php

namespace App\Models;

use Database\Factories\PartyAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One period during which a field officer is responsible for a party. Rows are closed, never deleted,
 * so the history of who collected from whom stays intact. Written only by AssignmentService.
 *
 * @property int $id
 * @property int $party_id
 * @property int $field_officer_id
 * @property Carbon $assigned_from
 * @property Carbon|null $assigned_until
 * @property int $assigned_by
 * @property int|null $ended_by
 */
#[Fillable(['party_id', 'field_officer_id', 'assigned_from', 'assigned_until', 'assigned_by', 'ended_by'])]
class PartyAssignment extends Model
{
    /** @use HasFactory<PartyAssignmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_from' => 'date',
            'assigned_until' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function fieldOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'field_officer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('assigned_until');
    }

    public function isActive(): bool
    {
        return $this->assigned_until === null;
    }
}
