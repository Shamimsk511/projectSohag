<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Support\Money;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One immutable ledger row. Mistakes are fixed with a reversal row, never by editing or deleting.
 *
 * @property int $id
 * @property int|null $account_id
 * @property int|null $party_id
 * @property int|null $field_officer_id
 * @property int|null $schedule_id
 * @property int|null $office_head_id
 * @property TransactionType $type
 * @property Money $amount
 * @property Carbon $transaction_date
 * @property string|null $reference
 * @property string|null $receipt_no
 * @property PaymentMethod|null $payment_method
 * @property string|null $description
 * @property string|null $override_reason
 * @property string|null $client_uuid
 * @property int|null $reverses_transaction_id
 * @property int $created_by
 * @property int|null $approved_by
 */
#[Fillable([
    'account_id', 'party_id', 'field_officer_id', 'schedule_id', 'office_head_id', 'type', 'amount', 'payment_method', 'reference',
    'receipt_no', 'description', 'override_reason', 'transaction_date', 'client_uuid', 'reverses_transaction_id', 'created_by',
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
            'payment_method' => PaymentMethod::class,
            'transaction_date' => 'date',
        ];
    }

    /**
     * Rows dated from $from to $to inclusive. Half-open on the next day because SQLite keeps the
     * date cast as "Y-m-d 00:00:00", which a plain BETWEEN on the end date would miss.
     *
     * @param  Builder<self>  $query
     */
    public function scopeDatedBetween(Builder $query, Carbon $from, Carbon $to): void
    {
        $query->where('transaction_date', '>=', $from->toDateString())
            ->where('transaction_date', '<', $to->copy()->addDay()->toDateString());
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The row this reversal cancels.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function reversedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'reverses_transaction_id');
    }

    /**
     * @return BelongsTo<OfficeHead, $this>
     */
    public function officeHead(): BelongsTo
    {
        return $this->belongsTo(OfficeHead::class);
    }

    /**
     * The reversal row cancelling this one, if any.
     *
     * @return HasOne<Transaction, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(Transaction::class, 'reverses_transaction_id');
    }
}
