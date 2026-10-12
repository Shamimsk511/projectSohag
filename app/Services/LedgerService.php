<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Balances computed from the ledger; nothing here is stored. A reversed row and its reversal both
 * drop out, so a reversal cancels the original whatever its type.
 */
class LedgerService
{
    public function savingBalance(Account $account): Money
    {
        return $this->sum($account, TransactionType::SavingDeposit)
            ->minus($this->sum($account, TransactionType::SavingWithdrawal));
    }

    public function loanReceived(Account $account): Money
    {
        return $this->sum($account, TransactionType::LoanCollection);
    }

    /**
     * Contract amount still to be received. Negative means over-collected.
     */
    public function loanOutstanding(Account $account): Money
    {
        return ($account->contract_amount ?? Money::zero())->minus($this->loanReceived($account));
    }

    /**
     * What must reach zero before the account can close: loan outstanding or saving balance.
     */
    public function remainingBalance(Account $account): Money
    {
        return $account->isLoan() ? $this->loanOutstanding($account) : $this->savingBalance($account);
    }

    private function sum(Account $account, TransactionType $type): Money
    {
        return Money::fromPoisha((int) $this->effective($account)->where('type', $type)->sum('amount'));
    }

    /**
     * The account's rows that still count: not reversed, and not reversals themselves.
     *
     * @return Builder<Transaction>
     */
    private function effective(Account $account): Builder
    {
        return Transaction::query()
            ->where('account_id', $account->id)
            ->where('type', '!=', TransactionType::Reversal)
            ->whereNotExists(fn (QueryBuilder $reversal) => $reversal->from('transactions', 'reversals')
                ->whereColumn('reversals.reverses_transaction_id', 'transactions.id'));
    }
}
