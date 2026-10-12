<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\AccountStatement;
use App\Support\Money;
use App\Support\StatementLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

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

    /**
     * Statement for an optional date period. Rows before `$from` fold into the opening figure, so the
     * closing figure over the whole history equals savingBalance() or loanReceived().
     */
    public function statement(Account $account, ?Carbon $from = null, ?Carbon $to = null): AccountStatement
    {
        $columns = $account->isLoan()
            ? [TransactionType::LoanCollection->value => 'in', TransactionType::LoanDisbursement->value => 'out']
            : [TransactionType::SavingDeposit->value => 'in', TransactionType::SavingWithdrawal->value => 'out'];

        $rows = Transaction::query()
            ->where('account_id', $account->id)
            ->whereIn('type', [...array_keys($columns), TransactionType::Reversal->value])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->each(fn (Transaction $row) => $row->setRelation('account', $account));
        $byId = $rows->keyBy('id');
        $reversedIds = $rows->pluck('reverses_transaction_id')->filter()->flip();

        $running = Money::zero();
        $opening = Money::zero();
        $lines = [];

        foreach ($rows as $row) {
            $original = $row->type === TransactionType::Reversal ? $byId->get($row->reverses_transaction_id) : $row;
            $column = $original ? ($columns[$original->type->value] ?? null) : null;

            if ($column === null || ($to && $row->transaction_date->gt($to))) {
                continue;
            }

            if ($row !== $original) {
                $row->setRelation('reversedTransaction', $original);
            }

            $amount = $row === $original ? $row->amount : Money::zero()->minus($row->amount);
            if ($column === 'in') {
                $running = $running->plus($amount);
            } elseif (! $account->isLoan()) {
                $running = $running->minus($amount);
            }

            if ($from && $row->transaction_date->lt($from)) {
                $opening = $running;

                continue;
            }

            $lines[] = new StatementLine(
                $row,
                $column === 'in' ? $amount : null,
                $column === 'out' ? $amount : null,
                $running,
                $reversedIds->has($row->id),
            );
        }

        return new AccountStatement($from, $to, $opening, $lines, $running);
    }

    /**
     * Office income and expense dated within the period, reversed rows left out.
     *
     * @return array{income: Money, expense: Money}
     */
    public function officeTotals(Carbon $from, Carbon $to): array
    {
        $sums = $this->effectiveRows()
            ->whereNull('account_id')
            ->whereIn('type', TransactionType::officeTypes())
            ->datedBetween($from, $to)
            ->groupBy('type')
            ->selectRaw('type, sum(amount) as total')
            ->pluck('total', 'type');

        return [
            'income' => Money::fromPoisha((int) ($sums[TransactionType::OfficeIncome->value] ?? 0)),
            'expense' => Money::fromPoisha((int) ($sums[TransactionType::OfficeExpense->value] ?? 0)),
        ];
    }

    /**
     * Collected so far on each schedule row, reversed rows left out. Every id gets an entry.
     *
     * @param  list<int>  $scheduleIds
     * @return array<int, Money>
     */
    public function collectedForSchedules(array $scheduleIds): array
    {
        $sums = $scheduleIds === [] ? collect() : $this->effectiveRows()
            ->whereIn('schedule_id', $scheduleIds)
            ->groupBy('schedule_id')
            ->selectRaw('schedule_id, sum(amount) as total')
            ->pluck('total', 'schedule_id');

        $collected = [];
        foreach ($scheduleIds as $id) {
            $collected[$id] = Money::fromPoisha((int) ($sums[$id] ?? 0));
        }

        return $collected;
    }

    private function sum(Account $account, TransactionType $type): Money
    {
        return Money::fromPoisha((int) $this->effective($account)->where('type', $type)->sum('amount'));
    }

    /**
     * The account's rows that still count.
     *
     * @return Builder<Transaction>
     */
    private function effective(Account $account): Builder
    {
        return $this->effectiveRows()->where('account_id', $account->id);
    }

    /**
     * Rows that still count: not reversed, and not reversals themselves.
     *
     * @return Builder<Transaction>
     */
    private function effectiveRows(): Builder
    {
        return Transaction::query()
            ->where('type', '!=', TransactionType::Reversal)
            ->whereNotExists(fn (QueryBuilder $reversal) => $reversal->from('transactions', 'reversals')
                ->whereColumn('reversals.reverses_transaction_id', 'transactions.id'));
    }
}
