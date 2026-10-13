<?php

namespace App\Services;

use App\Enums\Permission;
use App\Enums\TransactionType;
use App\Exceptions\LedgerRuleViolation;
use App\Models\OpeningBalance;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Only writer of the office opening cash. One immutable row, set once before money starts moving.
 */
class OpeningBalanceService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function current(): ?OpeningBalance
    {
        return OpeningBalance::query()->first();
    }

    /**
     * Office cash on hand at the start of `$asOf`. Allowed only once, not in the future, and not
     * after the first day money moved (book entries of carried-in balances do not count).
     */
    public function set(Money $amount, Carbon $asOf, ?string $note, User $actor): OpeningBalance
    {
        if (! $actor->can(Permission::SetOpeningBalances->value)) {
            $this->reject('as_of', 'opening_cash.forbidden');
        }

        return DB::transaction(function () use ($amount, $asOf, $note, $actor): OpeningBalance {
            if (OpeningBalance::query()->exists()) {
                $this->reject('as_of', 'opening_cash.already_set');
            }

            if ($asOf->toDateString() > today(config('app.display_timezone'))->toDateString()) {
                $this->reject('as_of', 'opening_cash.future');
            }

            $first = Transaction::query()->whereNotIn('type', TransactionType::openingTypes())->min('transaction_date');
            if ($first !== null && $asOf->toDateString() > Carbon::parse($first)->toDateString()) {
                $this->reject('as_of', 'opening_cash.after_first_entry');
            }

            $balance = OpeningBalance::create([
                'as_of' => $asOf->toDateString(),
                'amount' => $amount,
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'set_by' => $actor->id,
            ]);

            $this->audit->created('opening_cash.set', $balance, $actor);

            return $balance;
        });
    }

    private function reject(string $field, string $key): never
    {
        throw LedgerRuleViolation::of($field, $key);
    }
}
