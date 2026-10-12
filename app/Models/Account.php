<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Support\Money;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A party's saving or loan account. Balances are never stored here: LedgerService computes them
 * from transactions. Written only by AccountService.
 *
 * @property int $id
 * @property int $party_id
 * @property string $account_number
 * @property AccountType $account_type
 * @property Money|null $contract_amount
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property int|null $closed_by
 * @property string|null $close_reason
 * @property int $created_by
 */
#[Fillable([
    'party_id', 'account_number', 'account_type', 'contract_amount', 'opened_at', 'closed_at', 'closed_by',
    'close_reason', 'created_by',
])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'contract_amount' => Money::class,
            'opened_at' => 'date',
            'closed_at' => 'date',
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
     * Contract amount changes, newest first.
     *
     * @return HasMany<AccountAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(AccountAdjustment::class)->latest('id');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    public function isActive(): bool
    {
        return $this->closed_at === null;
    }

    public function isLoan(): bool
    {
        return $this->account_type === AccountType::Loan;
    }
}
