<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Support\Money;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One immutable ledger row. Mistakes are fixed with a reversal row, never by editing or deleting.
 *
 * @property int $id
 * @property int|null $account_id
 * @property TransactionType $type
 * @property Money $amount
 * @property Carbon $transaction_date
 * @property int|null $reverses_transaction_id
 * @property int $created_by
 */
#[Fillable([
    'account_id', 'party_id', 'field_officer_id', 'schedule_id', 'type', 'amount', 'payment_method', 'reference',
    'receipt_no', 'description', 'transaction_date', 'client_uuid', 'reverses_transaction_id', 'created_by',
    'approved_by',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Transactions are immutable; record a reversal instead.'));
        static::deleting(fn () => throw new LogicException('Transactions are immutable; record a reversal instead.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => Money::class,
            'transaction_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
