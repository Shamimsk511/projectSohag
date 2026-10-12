<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\CashHandoverFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Cash a field officer handed to the office, recorded (and so confirmed) by the staff who took it,
 * or written automatically with a cash collection (`transaction_id` set, confirmed by whoever
 * recorded the collection). Immutable; lowers the officer's cash in hand, never touches the ledger.
 *
 * @property int $id
 * @property int $field_officer_id
 * @property int|null $transaction_id
 * @property Money $amount
 * @property Carbon $handover_date
 * @property string|null $note
 * @property int $confirmed_by
 * @property Carbon $created_at
 */
#[Fillable(['field_officer_id', 'transaction_id', 'amount', 'handover_date', 'note', 'confirmed_by'])]
class CashHandover extends Model
{
    /** @use HasFactory<CashHandoverFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cash handovers are immutable.'));
        static::deleting(fn () => throw new LogicException('Cash handovers are immutable.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => Money::class,
            'handover_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function fieldOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'field_officer_id');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
