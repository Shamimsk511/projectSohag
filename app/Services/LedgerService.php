<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\CashHandover;
use App\Models\OfficeHead;
use App\Models\OpeningBalance;
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
    /** Loan "received" rows: installments plus what was received before go-live. */
    private const LOAN_RECEIVED = [TransactionType::LoanCollection, TransactionType::LoanOpeningReceived];

    /** Loan "handed over" rows: disbursements plus the principal of a loan carried in at go-live. */
    private const LOAN_DISBURSED = [TransactionType::LoanDisbursement, TransactionType::LoanOpeningDisbursement];

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

    public function fdrDeposited(Account $account): Money
    {
        return $this->sum($account, TransactionType::InvestmentDeposit);
    }

    public function fdrReturned(Account $account): Money
    {
        return $this->sum($account, TransactionType::InvestmentReturn);
    }

    /**
     * Money the NGO still holds for the member: deposited minus paid back. Negative if more went
     * out than came in (the agreed payback can exceed the deposits).
     */
    public function fdrHeld(Account $account): Money
    {
        return $this->fdrDeposited($account)->minus($this->fdrReturned($account));
    }

    /**
     * Payback still to be paid: the agreed payback (the deposits when none is agreed yet) minus
     * what was paid back. Must reach zero before the account can close.
     */
    public function fdrRemaining(Account $account): Money
    {
        return ($account->contract_amount ?? $this->fdrDeposited($account))->minus($this->fdrReturned($account));
    }

    /**
     * Whether any row on the account still counts (reversed rows and reversals do not).
     */
    public function hasEntries(Account $account): bool
    {
        return $this->effective($account)->exists();
    }

    public function hasEffectiveOfType(Account $account, TransactionType $type): bool
    {
        return $this->effective($account)->where('type', $type)->exists();
    }

    public function loanReceived(Account $account): Money
    {
        return $this->sum($account, self::LOAN_RECEIVED);
    }

    public function loanDisbursed(Account $account): Money
    {
        return $this->sum($account, self::LOAN_DISBURSED);
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
     * What must reach zero before the account can close: loan outstanding, FDR remaining payback or saving balance.
     */
    public function remainingBalance(Account $account): Money
    {
        return match ($account->account_type) {
            AccountType::Loan => $this->loanOutstanding($account),
            AccountType::Fdr => $this->fdrRemaining($account),
            AccountType::Saving => $this->savingBalance($account),
        };
    }

    /**
     * Statement for an optional date period. Rows before `$from` fold into the opening figure, so the
     * closing figure over the whole history equals savingBalance() or loanReceived().
     */
    public function statement(Account $account, ?Carbon $from = null, ?Carbon $to = null): AccountStatement
    {
        $columns = match ($account->account_type) {
            AccountType::Loan => [
                TransactionType::LoanCollection->value => 'in',
                TransactionType::LoanOpeningReceived->value => 'in',
                TransactionType::LoanDisbursement->value => 'out',
                TransactionType::LoanOpeningDisbursement->value => 'out',
            ],
            AccountType::Fdr => [
                TransactionType::InvestmentDeposit->value => 'in',
                TransactionType::InvestmentReturn->value => 'out',
            ],
            AccountType::Saving => [
                ...array_fill_keys(array_map(fn (TransactionType $type): string => $type->value, TransactionType::savingInTypes()), 'in'),
                TransactionType::SavingWithdrawal->value => 'out',
            ],
        };

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
     * Office income and expense per head in the period (rows without a head count under null),
     * reversed rows left out. Sorted by kind then head name.
     *
     * @return list<array{head: OfficeHead|null, kind: TransactionType, total: Money}>
     */
    public function officeTotalsByHead(Carbon $from, Carbon $to): array
    {
        $rows = $this->effectiveRows()
            ->whereIn('type', TransactionType::officeTypes())
            ->datedBetween($from, $to)
            ->groupBy('type', 'office_head_id')
            ->selectRaw('type, office_head_id, sum(amount) as total')
            ->get();
        $heads = OfficeHead::query()->whereIn('id', $rows->pluck('office_head_id')->filter()->all())->get()->keyBy('id');

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = [
                'head' => $row->office_head_id ? $heads->get($row->office_head_id) : null,
                'kind' => $row->type,
                'total' => Money::fromPoisha((int) $row->getAttribute('total')),
            ];
        }

        usort($lines, fn (array $a, array $b): int => [$a['kind']->value, $a['head']->name ?? ''] <=> [$b['kind']->value, $b['head']->name ?? '']);

        return $lines;
    }

    /**
     * Total received on each loan account, reversed rows left out. Every id gets an entry.
     *
     * @param  list<int>  $accountIds
     * @return array<int, Money>
     */
    public function loanReceivedFor(array $accountIds): array
    {
        return $this->sumsFor(self::LOAN_RECEIVED, $accountIds);
    }

    /**
     * Total handed over on each loan account, reversed rows left out. Every id gets an entry.
     *
     * @param  list<int>  $accountIds
     * @return array<int, Money>
     */
    public function loanDisbursedFor(array $accountIds): array
    {
        return $this->sumsFor(self::LOAN_DISBURSED, $accountIds);
    }

    /**
     * @param  list<TransactionType>  $types
     * @param  list<int>  $accountIds
     * @return array<int, Money>
     */
    private function sumsFor(array $types, array $accountIds): array
    {
        $sums = collect();
        foreach (array_chunk($accountIds, 1000) as $chunk) {
            $sums = $sums->union($this->effectiveRows()
                ->whereIn('type', $types)
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
     * Cash collections and saving and FDR deposits attributed to each officer within the period (open ends allowed).
     *
     * @param  list<int>  $officerIds
     * @return array<int, Money>
     */
    public function cashCollectedBy(array $officerIds, ?Carbon $from, ?Carbon $to): array
    {
        $sums = $officerIds === [] ? collect() : $this->withinDates($this->effectiveRows(), 'transaction_date', $from, $to)
            ->whereIn('field_officer_id', $officerIds)
            ->whereIn('type', TransactionType::collectionTypes())
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
     * and cash paid out (disbursements, withdrawals, FDR paybacks, office expenses).
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
            ->whereIn('type', [...TransactionType::collectionTypes(), TransactionType::OfficeIncome])
            ->sum('amount');
        $paidOut = $cashRows()
            ->whereIn('type', [TransactionType::LoanDisbursement, TransactionType::SavingWithdrawal, TransactionType::InvestmentReturn, TransactionType::OfficeExpense])
            ->sum('amount');

        return [
            'handovers' => Money::fromPoisha((int) $handovers),
            'other_in' => Money::fromPoisha((int) $otherIn),
            'out' => Money::fromPoisha((int) $paidOut),
        ];
    }

    /**
     * Office cash on hand up to and including the date: opening cash + handovers + other cash in - cash out.
     */
    public function officeCashBalance(?Carbon $asOf): Money
    {
        $cash = $this->officeCash(null, $asOf);

        return $this->openingCash($asOf)->plus($cash['handovers'])->plus($cash['other_in'])->minus($cash['out']);
    }

    /**
     * Office cash entered at go-live, counted once `$asOf` reaches its date (always when null).
     */
    public function openingCash(?Carbon $asOf = null): Money
    {
        $opening = OpeningBalance::query()->first();

        return $opening !== null && ($asOf === null || $asOf->toDateString() >= $opening->as_of->toDateString())
            ? $opening->amount
            : Money::zero();
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
            ->whereIn('type', self::LOAN_RECEIVED)
            ->whereIn('account_id', (clone $loans)->select('id'))
            ->sum('amount');

        return Money::fromPoisha($contract - $received);
    }

    /**
     * FDR deposits minus paybacks over every FDR account.
     */
    public function totalFdrHeld(): Money
    {
        $in = (int) $this->effectiveRows()->where('type', TransactionType::InvestmentDeposit)->sum('amount');
        $out = (int) $this->effectiveRows()->where('type', TransactionType::InvestmentReturn)->sum('amount');

        return Money::fromPoisha($in - $out);
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
     * Collections and saving and FDR deposits dated on the given day, reversed rows left out.
     */
    public function receivedOn(Carbon $day): Money
    {
        return Money::fromPoisha((int) $this->collectionRows($day)->sum('amount'));
    }

    /**
     * Effective amounts per transaction type dated within the period, open-ended when a bound is null
     * (types without rows are absent).
     *
     * @return array<string, Money>
     */
    public function totalsByType(?Carbon $from, ?Carbon $to): array
    {
        return $this->withinDates($this->effectiveRows(), 'transaction_date', $from, $to)
            ->groupBy('type')
            ->selectRaw('type, sum(amount) as total')
            ->pluck('total', 'type')
            ->map(fn ($total): Money => Money::fromPoisha((int) $total))
            ->all();
    }

    /**
     * Effective amounts per day and type within the period: Y-m-d => type value => total (empty days absent).
     *
     * @return array<string, array<string, Money>>
     */
    public function totalsByTypePerDay(Carbon $from, Carbon $to): array
    {
        $rows = $this->withinDates($this->effectiveRows(), 'transaction_date', $from, $to)
            ->groupBy('transaction_date', 'type')
            ->selectRaw('transaction_date, type, sum(amount) as total')
            ->get();

        $days = [];
        foreach ($rows as $row) {
            $days[Carbon::parse((string) $row->getRawOriginal('transaction_date'))->toDateString()][(string) $row->getRawOriginal('type')]
                = Money::fromPoisha((int) $row->getAttribute('total'));
        }

        return $days;
    }

    /**
     * Effective rows of the given types dated within the period (reversed rows and reversals left out).
     *
     * @param  list<TransactionType>  $types
     * @return Builder<Transaction>
     */
    public function rowsOfType(array $types, Carbon $from, Carbon $to): Builder
    {
        return $this->withinDates($this->effectiveRows(), 'transaction_date', $from, $to)->whereIn('type', $types);
    }

    /**
     * Collections and saving and FDR deposits per day within the period, keyed Y-m-d (days without rows are absent).
     *
     * @return array<string, Money>
     */
    public function receivedPerDay(Carbon $from, Carbon $to): array
    {
        return $this->withinDates($this->effectiveRows(), 'transaction_date', $from, $to)
            ->whereIn('type', TransactionType::collectionTypes())
            ->groupBy('transaction_date')
            ->selectRaw('transaction_date, sum(amount) as total')
            ->pluck('total', 'transaction_date')
            ->mapWithKeys(fn ($total, $date): array => [Carbon::parse((string) $date)->toDateString() => Money::fromPoisha((int) $total)])
            ->all();
    }

    /**
     * Loan collections, savings and FDR deposits dated on the day, reversed rows left out.
     *
     * @return Builder<Transaction>
     */
    public function collectionRows(Carbon $day): Builder
    {
        return $this->effectiveRows()
            ->whereIn('type', TransactionType::collectionTypes())
            ->datedBetween($day, $day);
    }

    /**
     * Loans handed over in the period: number of disbursements, principal, and the agreed extra of the
     * accounts involved (contract minus everything disbursed on the account, counted once per account).
     *
     * @return array{count: int, principal: Money, extra: Money}
     */
    public function loansIssued(Carbon $from, Carbon $to): array
    {
        $rows = $this->rowsOfType([TransactionType::LoanDisbursement], $from, $to)
            ->with('account')
            ->get();

        $accounts = $rows->pluck('account')->filter()->unique('id');
        $disbursed = $this->loanDisbursedFor($accounts->pluck('id')->map(fn ($id): int => (int) $id)->values()->all());
        $extra = $accounts->sum(fn (Account $account): int => $account->contract_amount === null
            ? 0
            : $account->contract_amount->minus($disbursed[$account->id])->poisha);

        return [
            'count' => $rows->count(),
            'principal' => Money::fromPoisha((int) $rows->sum(fn (Transaction $row): int => $row->amount->poisha)),
            'extra' => Money::fromPoisha((int) $extra),
        ];
    }

    /**
     * Time of each officer's latest collection or deposit dated on the day (reversed rows left out);
     * officers with none have no entry.
     *
     * @param  list<int>  $officerIds
     * @return array<int, Carbon>
     */
    public function lastCollectionAt(array $officerIds, Carbon $day): array
    {
        $times = $this->collectionRows($day)
            ->whereIn('field_officer_id', $officerIds)
            ->groupBy('field_officer_id')
            ->selectRaw('field_officer_id, max(created_at) as last_at')
            ->pluck('last_at', 'field_officer_id');

        $result = [];
        foreach ($times as $officerId => $time) {
            $result[(int) $officerId] = Carbon::parse($time, 'UTC');
        }

        return $result;
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

    /**
     * @param  TransactionType|list<TransactionType>  $types
     */
    private function sum(Account $account, TransactionType|array $types): Money
    {
        return Money::fromPoisha((int) $this->effective($account)->whereIn('type', (array) $types)->sum('amount'));
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
