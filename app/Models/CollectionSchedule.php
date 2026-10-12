<?php

namespace App\Models;

use App\Enums\ScheduleStatus;
use App\Support\Money;
use Database\Factories\CollectionScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Expected collection for an account on one date. Written only by CollectionService (and, for its
 * status, TransactionService once collections settle it).
 *
 * @property int $id
 * @property int $account_id
 * @property int $party_id
 * @property int|null $field_officer_id
 * @property Carbon $due_date
 * @property Money $expected_amount
 * @property ScheduleStatus $status
 * @property string|null $notes
 */
#[Fillable(['account_id', 'party_id', 'field_officer_id', 'due_date', 'expected_amount', 'status', 'notes'])]
class CollectionSchedule extends Model
{
    /** @use HasFactory<CollectionScheduleFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'expected_amount' => Money::class,
            'status' => ScheduleStatus::class,
        ];
    }

    /**
     * Rows due on $date. A range, not equality: SQLite stores the date cast as "Y-m-d 00:00:00".
     *
     * @param  Builder<self>  $query
     */
    public function scopeDueOn(Builder $query, Carbon $date): void
    {
        $query->where('due_date', '>=', $date->toDateString())
            ->where('due_date', '<', $date->copy()->addDay()->toDateString());
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
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
}
