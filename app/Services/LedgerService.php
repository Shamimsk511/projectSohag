<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\CashHandover;
use App\Models\Transaction;
use App\Support\AccountStatement;
use App\Support\Money;
use App\Support\StatementLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Balances computed from the ledger; nothing here is stored. A reversed row and its reversal both
 * drop out, so a reversal cancels the original whatever its type.
 */
class LedgerService
{
    public function savingBalance(Account $account): Money
    {
        return $this->savingCredited($account)->minus($this->sum($account, TransactionType::SavingWithdrawal));
    }

    /**
     * Everything added to a saving account (deposits, opening balance, maturity credit), withdrawals
     * not taken off. Measured against the agreed amount.
     */
    public function savingCredited(Account $account): Money
    {
        return Money::fromPoisha((int) $this->effective($account)->whereIn('type', TransactionType::savingInTypes())->sum('amount'));
    }

    /**
     * Whether any row on the account still counts (reversed rows and reversals do not).
     */
    public function hasEntries(Account $account): bool
    {
        return $this->effective($account)->exists();
    }

    public function loanReceived(Account $account): Money
    {
        return $this->sum($account, TransactionType::LoanCollection);
    }

    public function loanDisbursed(Account $account): Money
    {
        return $this->sum($account, TransactionType::LoanDisbursement);
    }

    /**
     * Contract amount minus what was handed over, as set by the office. Null while nothing was
     * disbursed through the system (older loans), so no misleading figure is shown.
     */
    public function agreedExtra(Account $account): ?Money
    {
        $disbursed = $this->loanDisbursed($account);

        return $account->contract_amount !== null && $disbursed->isPositive()
            ? $account->contract_amount->minus($disbursed)
            : null;
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
            : [
                ...array_fill_keys(array_map(fn (TransactionType $type): string => $type->value, TransactionType::savingInTypes()), 'in'),
                TransactionType::SavingWithdrawal->value => 'out',
            ];

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

            $row->setRelation('reversedTransaction', $row === $original ? null : $original);

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
     * Total received on each loan account, reversed rows left out. Every id gets an entry.
     *
     * @param  list<int>  $accountIds
     * @return array<int, Money>
     */
    public function loanReceivedFor(array $accountIds): array
    {
        return $this->sumsFor(TransactionType::LoanCollection, $accountIds);
    }

    /**
     * Total handed over on each loan account, reversed rows left out. Every id gets an entry.
     *
     * @param  list<int>  $accountIds
     * @return array<int, Money>
     */
    public function loanDisbursedFor(array $accountIds): array
    {
        return $this->sumsFor(TransactionType::LoanDisbursement, $accountIds);
    }

    /**
     * @param  list<int>  $accountIds
     * @return array<int, Money>
     */
    private function sumsFor(TransactionType $type, array $accountIds): array
    {
        $sums = collect();
        foreach (array_chunk($accountIds, 1000) as $chunk) {
            $sums = $sums->union($this->effectiveRows()
                ->where('type', $type)
                ->whereIn('account_id', $chunk)
                ->groupBy('account_id')
                ->selectRaw('account_id, sum(amount) as total')
                ->pluck('total', 'account_id'));
        }

        $result = [];
        foreach ($accountIds as $id) {
            $result[$id] = Money::fromPoisha((int) ($sums[$id] ?? 0));
        }

        return $result;
    }

    /**
     * Collected so far on each schedule row, reversed rows left out. Every id gets an entry.
     *
     * @param  list<int>  $scheduleIds
     * @return array<int, Money>
     */
    public function collectedForSchedules(array $scheduleIds): array
    {
        $sums = collect();
        foreach (array_chunk($scheduleIds, 1000) as $chunk) {
            $sums = $sums->union($this->effectiveRows()
                ->whereIn('schedule_id', $chunk)
                ->groupBy('schedule_id')
                ->selectRaw('schedule_id, sum(amount) as total')
                ->pluck('total', 'schedule_id'));
        }

        $collected = [];
        foreach ($scheduleIds as $id) {
            $collected[$id] = Money::fromPoisha((int) ($sums[$id] ?? 0));
        }

        return $collected;
    }

    /**
     * Cash each field officer collected and has not handed to the office yet: effective cash
     * collections attributed to them minus their handovers, up to and including `$asOf` (all time
     * when null). Negative when a collection was reversed after its cash was handed over. Every id
     * gets an entry.
     *
     * @param  list<int>  $officerIds
     * @return array<int, Money>
     */
    public function cashInHand(array $officerIds, ?Carbon $asOf = null): array
    {
        $collected = $this->cashCollectedBy($officerIds, null, $asOf);
        $handedOver = $this->cashHandedOverBy($officerIds, null, $asOf);

        return array_map(fn (int $id): Money => $collected[$id]->minus($handedOver[$id]), array_combine($officerIds, $officerIds));
    }

    /**
     * Cash collections and saving deposits attributed to each officer within the period (open ends allowed).
     *
     * @param  list<int>  $officerIds
     * @return array<int, Money>
     */
    public function cashCollectedBy(array $officerIds, ?Carbon $from, ?Carbon $to): array
    {
        $sums = $officerIds === [] ? collect() : $this->withinDates($this->effectiveRows(), 'transaction_date', $from, $to)
            ->whereIn('field_officer_id', $officerIds)
            ->whereIn('type', [TransactionType::LoanCollection, TransactionType::SavingDeposit])
            ->where('payment_method', PaymentMethod::Cash)
            ->groupBy('field_officer_id')
            ->selectRaw('field_officer_id, sum(amount) as total')
            ->pluck('total', 'field_officer_id');

        return $this->moneyById($officerIds, $sums);
    }

    /**
     * Cash each officer handed to the office within the period (open ends allowed). An automatic
     * handover stops counting once its collection is reversed.
     *
     * @param  list<int>  $officerIds
     * @return array<int, Money>
     */
    public function cashHandedOverBy(array $officerIds, ?Carbon $from, ?Carbon $to): array
    {
        $sums = $officerIds === [] ? collect() : $this->withinDates($this->effectiveHandovers(), 'handover_date', $from, $to)
            ->whereIn('field_officer_id', $officerIds)
            ->groupBy('field_officer_id')
            ->selectRaw('field_officer_id, sum(amount) as total')
            ->pluck('total', 'field_officer_id');

        return $this->moneyById($officerIds, $sums);
    }

    /**
     * Cash the office itself moved within the period (open ends allowed): handovers received, other
     * cash received (counter collections and deposits, office income: rows without a field officer)
     * and cash paid out (disbursements, withdrawals, office expenses).
     *
     * @return array{handovers: Money, other_in: Money, out: Money}
     */
    public function officeCash(?Carbon $from, ?Carbon $to): array
    {
        $handovers = $this->withinDates($this->effectiveHandovers(), 'handover_date', $from, $to)->sum('amount');
        $cashRows = fn () => $this->withinDates($this->effectiveRows(), 'transaction_date', $from, $to)
            ->where('payment_method', PaymentMethod::Cash);

        $otherIn = $cashRows()
            ->whereNull('field_officer_id')
            ->whereIn('type', [TransactionType::LoanCollection, TransactionType::SavingDeposit, TransactionType::OfficeIncome])
            ->sum('amount');
        $paidOut = $cashRows()
            ->whereIn('type', [TransactionType::LoanDisbursement, TransactionType::SavingWithdrawal, TransactionType::OfficeExpense])
            ->sum('amount');

        return [
            'handovers' => Money::fromPoisha((int) $handovers),
            'other_in' => Money::fromPoisha((int) $otherIn),
            'out' => Money::fromPoisha((int) $paidOut),
        ];
    }

    /**
     * Office cash on hand up to and including the date: handovers + other cash in - cash out.
     */
    public function officeCashBalance(?Carbon $asOf): Money
    {
        $cash = $this->officeCash(null, $asOf);

        return $cash['handovers']->plus($cash['other_in'])->minus($cash['out']);
    }

    /**
     * Contract amount still to be received across all active loan accounts. Over-collected accounts
     * count with their negative figure, so the total matches the sum of the account pages.
     */
    public function totalLoanOutstanding(): Money
    {
        $loans = Account::query()->where('account_type', AccountType::Loan)->whereNull('closed_at');
        $contract = (int) (clone $loans)->sum('contract_amount');
        $received = (int) $this->effectiveRows()
            ->where('type', TransactionType::LoanCollection)
            ->whereIn('account_id', (clone $loans)->select('id'))
            ->sum('amount');

        return Money::fromPoisha($contract - $received);
    }

    /**
     * Saving in-rows minus withdrawals over every saving account.
     */
    public function totalSavingBalance(): Money
    {
        $in = (int) $this->effectiveRows()->whereIn('type', TransactionType::savingInTypes())->sum('amount');
        $out = (int) $this->effectiveRows()->where('type', TransactionType::SavingWithdrawal)->sum('amount');

        return Money::fromPoisha($in - $out);
    }

    /**
     * Collections and saving deposits dated on the given day, reversed rows left out.
     */
    public function receivedOn(Carbon $day): Money
    {
        return Money::fromPoisha((int) $this->collectionRows($day)->sum('amount'));
    }

    /**
     * Effective amounts per transaction type dated within the period (types without rows are absent).
     *
     * @return array<string, Money>
     */
    public function totalsByType(Carbon $from, Carbon $to): array
    {
        return $this->withinDates($this->effectiveRows(), 'transaction_date', $from, $to)
            ->groupBy('type')
            ->selectRaw('type, sum(amount) as total')
            ->pluck('total', 'type')
            ->map(fn ($total): Money => Money::fromPoisha((int) $total))
            ->all();
    }

    /**
     * Collections and saving deposits per day within the period, keyed Y-m-d (days without rows are absent).
     *
     * @return array<string, Money>
     */
    public function receivedPerDay(Carbon $from, Carbon $to): array
    {
        return $this->withinDates($this->effectiveRows(), 'transaction_date', $from, $to)
            ->whereIn('type', [TransactionType::LoanCollection, TransactionType::SavingDeposit])
            ->groupBy('transaction_date')
            ->selectRaw('transaction_date, sum(amount) as total')
            ->pluck('total', 'transaction_date')
            ->mapWithKeys(fn ($total, $date): array => [Carbon::parse((string) $date)->toDateString() => Money::fromPoisha((int) $total)])
            ->all();
    }

    /**
     * Loan collections and saving deposits dated on the day, reversed rows left out.
     *
     * @return Builder<Transaction>
     */
    public function collectionRows(Carbon $day): Builder
    {
        return $this->effectiveRows()
            ->whereIn('type', [TransactionType::LoanCollection, TransactionType::SavingDeposit])
            ->datedBetween($day, $day);
    }

    /**
     * @template TQuery of \Illuminate\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function withinDates(Builder $query, string $column, ?Carbon $from, ?Carbon $to): Builder
    {
        return $query
            ->when($from !== null, fn ($q) => $q->where($column, '>=', $from?->toDateString()))
            ->when($to !== null, fn ($q) => $q->where($column, '<', $to?->copy()->addDay()->toDateString()));
    }

    /**
     * @param  list<int>  $ids
     * @param  Collection<int|string, mixed>  $sums
     * @return array<int, Money>
     */
    private function moneyById(array $ids, Collection $sums): array
    {
        $money = [];
        foreach ($ids as $id) {
            $money[$id] = Money::fromPoisha((int) ($sums[$id] ?? 0));
        }

        return $money;
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
     * Handovers that still count: recorded by hand, or written for a collection not reversed since.
     *
     * @return Builder<CashHandover>
     */
    private function effectiveHandovers(): Builder
    {
        return CashHandover::query()
            ->whereNotExists(fn (QueryBuilder $reversal) => $reversal->from('transactions', 'reversals')
                ->whereColumn('reversals.reverses_transaction_id', 'cash_handovers.transaction_id'));
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
