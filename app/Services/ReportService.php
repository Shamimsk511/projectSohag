<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\PaymentMethod;
use App\Enums\ScheduleStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\CashHandover;
use App\Models\CollectionSchedule;
use App\Models\OfficeHead;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Installments;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Report figures. Everything is computed from schedules and the ledger on each call; nothing is stored.
 */
class ReportService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly CollectionService $collections,
    ) {}

    /**
     * Admin dashboard for one Dhaka day: expected vs collected on that day's rows, standing totals, per-officer table.
     *
     * @return array{
     *     expected: Money, collected: Money, received_today: Money,
     *     counts: array<string, int>,
     *     loan_outstanding: Money, saving_balance: Money, fdr_held: Money, cash_in_hand: Money,
     *     loans: array{count: int, principal: Money, extra: Money},
     *     office: array{income: Money, expense: Money},
     *     officers: list<array{officer: User, rows: int, paid: int, partial: int, missed: int, expected: Money, collected: Money, rate: int, cash: Money, last_at: Carbon|null, members: list<array{schedule: CollectionSchedule, collected: Money, remaining: Money}>}>,
     *     unpaid: array{members: int, amount: Money, rows: list<array{schedule: CollectionSchedule, collected: Money, remaining: Money}>, older_members: int, older_from: Carbon, older_to: Carbon}
     * }
     */
    public function dashboard(Carbon $day): array
    {
        [$rows, $collected] = $this->scheduleRows($day, $day);
        $totals = $this->scheduleTotals($rows, $collected);

        $rows->load(['party:id,party_code,name,phone', 'account:id,account_number', 'fieldOfficer:id,name']);
        $members = $rows
            ->map(fn (CollectionSchedule $row): array => $this->scheduleLine($row, $collected[$row->id]))
            ->sortBy([
                fn (array $a, array $b): int => $b['remaining']->isPositive() <=> $a['remaining']->isPositive(),
                fn (array $a, array $b): int => strcmp($a['schedule']->party->name, $b['schedule']->party->name),
            ])
            ->values();
        $unpaid = $members->filter(fn (array $line): bool => $line['remaining']->isPositive())->values();

        $lines = $this->officerLines($rows, $collected);
        $officerIds = array_map(fn (array $line): int => $line['officer']->id, $lines);
        $cash = $this->ledger->cashInHand($officerIds);
        $lastAt = $this->ledger->lastCollectionAt($officerIds, $day);
        $officers = array_map(fn (array $line): array => $line + [
            'cash' => $cash[$line['officer']->id],
            'last_at' => $lastAt[$line['officer']->id] ?? null,
            'members' => $members->filter(fn (array $member): bool => $member['schedule']->field_officer_id === $line['officer']->id)->values()->all(),
        ], $lines);

        $olderFrom = $day->copy()->subDays(30);
        $olderTo = $day->copy()->subDay();

        return [
            'expected' => $totals['expected'],
            'collected' => $totals['collected'],
            'received_today' => $this->ledger->receivedOn($day),
            'counts' => $totals['counts'],
            'loan_outstanding' => $this->ledger->totalLoanOutstanding(),
            'saving_balance' => $this->ledger->totalSavingBalance(),
            'fdr_held' => $this->ledger->totalFdrHeld(),
            'cash_in_hand' => $this->ledger->totalCashWithOfficers(),
            'loans' => $this->ledger->loansIssued($day, $day),
            'office' => $this->ledger->officeTotals($day, $day),
            'officers' => $officers,
            'unpaid' => [
                'members' => $unpaid->pluck('schedule.party_id')->unique()->count(),
                'amount' => Money::fromPoisha((int) $unpaid->sum(fn (array $line): int => $line['remaining']->poisha)),
                'rows' => $unpaid->all(),
                'older_members' => CollectionSchedule::query()
                    ->where('due_date', '>=', $olderFrom->toDateString())
                    ->where('due_date', '<', $day->toDateString())
                    ->whereIn('status', [ScheduleStatus::Missed, ScheduleStatus::PartiallyPaid])
                    ->distinct()
                    ->count('party_id'),
                'older_from' => $olderFrom,
                'older_to' => $olderTo,
            ],
        ];
    }

    /**
     * One schedule row with what was collected on it and what is still due (never below zero).
     *
     * @return array{schedule: CollectionSchedule, collected: Money, remaining: Money}
     */
    private function scheduleLine(CollectionSchedule $schedule, Money $collected): array
    {
        return [
            'schedule' => $schedule,
            'collected' => $collected,
            'remaining' => $schedule->expected_amount->greaterThan($collected)
                ? $schedule->expected_amount->minus($collected)
                : Money::zero(),
        ];
    }

    /**
     * Newest ledger rows dated on the Dhaka day, any type (reversals included), for the live feed.
     *
     * @return Collection<int, Transaction>
     */
    public function activity(Carbon $day, int $limit = 30): Collection
    {
        return Transaction::query()
            ->datedBetween($day, $day)
            ->with(['party', 'account', 'fieldOfficer', 'createdBy', 'officeHead', 'reversal'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Collection vs expected per field officer over a Dhaka date range (cancelled rows and rows
     * without an officer left out). Rate counts each row up to its expected amount.
     *
     * @return list<array{officer: User, rows: int, paid: int, partial: int, missed: int, expected: Money, collected: Money, rate: int}>
     */
    public function officerPerformance(Carbon $from, Carbon $to): array
    {
        [$rows, $collected] = $this->scheduleRows($from, $to);

        return $this->officerLines($rows->whereNotNull('field_officer_id'), $collected);
    }

    /**
     * Non-cancelled schedule rows due in the period with what the ledger says was collected on each.
     *
     * @return array{0: EloquentCollection<int, CollectionSchedule>, 1: array<int, Money>}
     */
    private function scheduleRows(Carbon $from, Carbon $to): array
    {
        $rows = CollectionSchedule::query()
            ->where('due_date', '>=', $from->toDateString())
            ->where('due_date', '<', $to->copy()->addDay()->toDateString())
            ->where('status', '!=', ScheduleStatus::Cancelled)
            ->get(['id', 'account_id', 'party_id', 'field_officer_id', 'due_date', 'expected_amount', 'status']);

        return [$rows, $this->ledger->collectedForSchedules($rows->modelKeys())];
    }

    /**
     * @param  Collection<int, CollectionSchedule>  $rows
     * @param  array<int, Money>  $collected
     * @return array{expected: Money, collected: Money, counts: array<string, int>}
     */
    private function scheduleTotals(Collection $rows, array $collected): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->status->value] = ($counts[$row->status->value] ?? 0) + 1;
        }

        return [
            'expected' => Money::fromPoisha((int) $rows->sum(fn (CollectionSchedule $row): int => $row->expected_amount->poisha)),
            'collected' => Money::fromPoisha(array_sum(array_map(fn (Money $money): int => $money->poisha, $collected))),
            'counts' => $counts,
        ];
    }

    /**
     * @param  Collection<int, CollectionSchedule>  $rows
     * @param  array<int, Money>  $collected
     * @return list<array{officer: User, rows: int, paid: int, partial: int, missed: int, expected: Money, collected: Money, rate: int}>
     */
    private function officerLines(Collection $rows, array $collected): array
    {
        return User::query()
            ->whereIn('id', $rows->pluck('field_officer_id')->filter()->unique())
            ->orderBy('name')
            ->get()
            ->map(function (User $officer) use ($rows, $collected): array {
                $mine = $rows->where('field_officer_id', $officer->id);
                $expected = (int) $mine->sum(fn (CollectionSchedule $row): int => $row->expected_amount->poisha);
                $counted = (int) $mine->sum(fn (CollectionSchedule $row): int => min($collected[$row->id]->poisha, $row->expected_amount->poisha));

                return [
                    'officer' => $officer,
                    'rows' => $mine->count(),
                    'paid' => $mine->where('status', ScheduleStatus::Paid)->count(),
                    'partial' => $mine->where('status', ScheduleStatus::PartiallyPaid)->count(),
                    'missed' => $mine->where('status', ScheduleStatus::Missed)->count(),
                    'expected' => Money::fromPoisha($expected),
                    'collected' => Money::fromPoisha((int) $mine->sum(fn (CollectionSchedule $row): int => $collected[$row->id]->poisha)),
                    'rate' => $expected > 0 ? (int) round($counted * 100 / $expected) : 0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Everything received on one Dhaka day (loan collections and saving deposits), optionally one officer's.
     *
     * @return array{
     *     rows: Collection<int, Transaction>, total: Money,
     *     by_officer: list<array{name: string|null, count: int, total: Money}>,
     *     by_method: list<array{method: PaymentMethod|null, count: int, total: Money}>
     * }
     */
    public function dailyCollection(Carbon $day, ?int $officerId = null): array
    {
        $rows = $this->ledger->collectionRows($day)
            ->when($officerId !== null, fn ($query) => $query->where('field_officer_id', $officerId))
            ->with(['party:id,party_code,name', 'account:id,account_number,account_type', 'fieldOfficer:id,name'])
            ->orderBy('id')
            ->get();

        $sum = fn (Collection $group): int => (int) $group->sum(fn (Transaction $row): int => $row->amount->poisha);

        return [
            'rows' => $rows,
            'total' => Money::fromPoisha($sum($rows)),
            'by_officer' => $rows->groupBy('field_officer_id')->map(fn (Collection $group): array => [
                'name' => $group->first()?->fieldOfficer?->name,
                'count' => $group->count(),
                'total' => Money::fromPoisha($sum($group)),
            ])->sortBy(fn (array $line): string => $line['name'] ?? '')->values()->all(),
            'by_method' => $rows->groupBy(fn (Transaction $row): string => $row->payment_method->value ?? '')->map(fn (Collection $group): array => [
                'method' => $group->first()->payment_method,
                'count' => $group->count(),
                'total' => Money::fromPoisha($sum($group)),
            ])->values()->all(),
        ];
    }

    /**
     * Active loan accounts with something still to receive. Overdue = what past-due schedule rows
     * (not paid, not cancelled) have not yet collected; it is part of the outstanding figure.
     * Sorted by overdue, then outstanding, largest first.
     *
     * @return array{
     *     lines: list<array{account: Account, contract: Money, extra: Money|null, received: Money, outstanding: Money, overdue: Money, overdue_rows: int, oldest_due: Carbon|null}>,
     *     outstanding: Money, overdue: Money
     * }
     */
    public function outstanding(Carbon $today, ?int $areaId = null, bool $overdueOnly = false): array
    {
        $accounts = Account::query()
            ->where('account_type', AccountType::Loan)
            ->whereNull('closed_at')
            ->whereHas('party', fn ($party) => $party->when($areaId !== null, fn ($query) => $query->where('area_id', $areaId)))
            ->with(['party:id,party_code,name,phone,area_id', 'party.area:id,name', 'party.activeAssignment.fieldOfficer:id,name'])
            ->get();
        $ids = $accounts->modelKeys();
        $received = $this->ledger->loanReceivedFor($ids);
        $disbursed = $this->ledger->loanDisbursedFor($ids);

        $late = CollectionSchedule::query()
            ->whereIn('account_id', $ids)
            ->where('due_date', '<', $today->toDateString())
            ->whereIn('status', [ScheduleStatus::Pending, ScheduleStatus::PartiallyPaid, ScheduleStatus::Missed])
            ->get(['id', 'account_id', 'due_date', 'expected_amount']);
        $collected = $this->ledger->collectedForSchedules($late->modelKeys());

        $lines = [];
        foreach ($accounts as $account) {
            $outstanding = ($account->contract_amount ?? Money::zero())->minus($received[$account->id]);
            $rows = $late->where('account_id', $account->id)
                ->filter(fn (CollectionSchedule $row): bool => $row->expected_amount->greaterThan($collected[$row->id]));
            $overdue = (int) $rows->sum(fn (CollectionSchedule $row): int => $row->expected_amount->poisha - $collected[$row->id]->poisha);

            if (! $outstanding->isPositive() || ($overdueOnly && $overdue === 0)) {
                continue;
            }

            $lines[] = [
                'account' => $account,
                'contract' => $account->contract_amount ?? Money::zero(),
                'extra' => $disbursed[$account->id]->isPositive() ? ($account->contract_amount ?? Money::zero())->minus($disbursed[$account->id]) : null,
                'received' => $received[$account->id],
                'outstanding' => $outstanding,
                'overdue' => Money::fromPoisha(min($overdue, $outstanding->poisha)),
                'overdue_rows' => $rows->count(),
                'oldest_due' => $rows->min('due_date'),
            ];
        }

        usort($lines, fn (array $a, array $b): int => [$b['overdue']->poisha, $b['outstanding']->poisha] <=> [$a['overdue']->poisha, $a['outstanding']->poisha]);

        return [
            'lines' => $lines,
            'outstanding' => Money::fromPoisha(array_sum(array_map(fn (array $line): int => $line['outstanding']->poisha, $lines))),
            'overdue' => Money::fromPoisha(array_sum(array_map(fn (array $line): int => $line['overdue']->poisha, $lines))),
        ];
    }

    /**
     * Active loans with nothing left to receive, oldest last payment first. Listed for a one-click close; nothing is
     * closed automatically.
     *
     * @return list<array{account: Account, contract: Money, received: Money, last_paid: Carbon|null}>
     */
    public function readyToClose(): array
    {
        $accounts = Account::query()
            ->where('account_type', AccountType::Loan)
            ->whereNull('closed_at')
            ->with('party:id,party_code,name')
            ->get();
        $received = $this->ledger->loanReceivedFor($accounts->modelKeys());
        $paidOff = $accounts->filter(fn (Account $account): bool => $account->contract_amount?->isPositive() === true
            && ! $account->contract_amount->minus($received[$account->id])->isPositive())->values();
        $lastPaid = $this->ledger->lastCollectionDateFor($paidOff->modelKeys());

        return $paidOff
            ->map(fn (Account $account): array => [
                'account' => $account,
                'contract' => $account->contract_amount ?? Money::zero(),
                'received' => $received[$account->id],
                'last_paid' => $lastPaid[$account->id] ?? null,
            ])
            ->sortBy(fn (array $line): string => $line['last_paid']?->toDateString() ?? '')
            ->values()
            ->all();
    }

    /**
     * Schedule rows due in the period that ended missed or partly paid, with what is still uncollected.
     *
     * @return array{
     *     rows: list<array{schedule: CollectionSchedule, collected: Money, remaining: Money}>,
     *     expected: Money, collected: Money, remaining: Money
     * }
     */
    public function missedAndPartial(Carbon $from, Carbon $to, ?ScheduleStatus $status = null, ?int $officerId = null): array
    {
        $schedules = CollectionSchedule::query()
            ->where('due_date', '>=', $from->toDateString())
            ->where('due_date', '<', $to->copy()->addDay()->toDateString())
            ->whereIn('status', $status === null ? [ScheduleStatus::Missed, ScheduleStatus::PartiallyPaid] : [$status])
            ->when($officerId !== null, fn ($query) => $query->where('field_officer_id', $officerId))
            ->with(['party:id,party_code,name,phone', 'account:id,account_number', 'fieldOfficer:id,name'])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
        $collected = $this->ledger->collectedForSchedules($schedules->modelKeys());

        $rows = $schedules->map(fn (CollectionSchedule $schedule): array => $this->scheduleLine($schedule, $collected[$schedule->id]))->all();

        $sum = fn (callable $pick): Money => Money::fromPoisha(array_sum(array_map($pick, $rows)));

        return [
            'rows' => $rows,
            'expected' => $sum(fn (array $row): int => $row['schedule']->expected_amount->poisha),
            'collected' => $sum(fn (array $row): int => $row['collected']->poisha),
            'remaining' => $sum(fn (array $row): int => $row['remaining']->poisha),
        ];
    }

    /**
     * Cash position for a Dhaka date range. Per officer: cash in hand at the start, cash collected,
     * cash handed over, cash in hand at the end. Office: cash on hand at the start, handovers and
     * other cash received, cash paid out, cash on hand at the end. Cash only (bank and mobile
     * banking rows are not physical cash).
     *
     * @return array{
     *     officers: list<array{officer: User, opening: Money, collected: Money, handed_over: Money, closing: Money}>,
     *     officers_closing: Money,
     *     office: array{opening: Money, handovers: Money, other_in: Money, out: Money, closing: Money}
     * }
     */
    public function cashReconciliation(Carbon $from, Carbon $to): array
    {
        $ids = array_values(array_unique([
            ...Transaction::query()->whereNotNull('field_officer_id')->distinct()->pluck('field_officer_id')->all(),
            ...CashHandover::query()->distinct()->pluck('field_officer_id')->all(),
        ]));
        $ids = array_map('intval', $ids);
        $dayBefore = $from->copy()->subDay();

        $opening = $this->ledger->cashInHand($ids, $dayBefore);
        $collected = $this->ledger->cashCollectedBy($ids, $from, $to);
        $handed = $this->ledger->cashHandedOverBy($ids, $from, $to);
        $closing = $this->ledger->cashInHand($ids, $to);

        $lines = User::query()->whereIn('id', $ids)->orderBy('name')->get()
            ->map(fn (User $officer): array => [
                'officer' => $officer,
                'opening' => $opening[$officer->id],
                'collected' => $collected[$officer->id],
                'handed_over' => $handed[$officer->id],
                'closing' => $closing[$officer->id],
            ])
            ->filter(fn (array $line): bool => ! ($line['opening']->isZero() && $line['collected']->isZero() && $line['handed_over']->isZero() && $line['closing']->isZero()))
            ->values()
            ->all();

        $movement = $this->ledger->officeCash($from, $to);

        return [
            'officers' => $lines,
            'officers_closing' => Money::fromPoisha(array_sum(array_map(fn (Money $money): int => $money->poisha, $closing))),
            'office' => [
                'opening' => $this->ledger->officeCashBalance($dayBefore),
                ...$movement,
                'closing' => $this->ledger->officeCashBalance($to),
            ],
        ];
    }

    /**
     * Loans handed over in the period, oldest first: principal, contract and the agreed extra of each
     * account (contract minus everything disbursed on it; the period total counts each account once).
     *
     * @return array{
     *     rows: list<array{transaction: Transaction, account: Account, contract: Money|null, extra: Money|null, officer: User|null}>,
     *     count: int, principal: Money, extra: Money
     * }
     */
    public function loanRegister(Carbon $from, Carbon $to): array
    {
        $rows = $this->ledger->rowsOfType([TransactionType::LoanDisbursement], $from, $to)
            ->with(['account.party.activeAssignment.fieldOfficer'])
            ->orderBy('transaction_date')->orderBy('id')
            ->get();
        $disbursed = $this->ledger->loanDisbursedFor($rows->pluck('account_id')->unique()->map(fn ($id): int => (int) $id)->values()->all());

        $lines = [];
        foreach ($rows as $row) {
            $account = $row->account;
            if ($account === null) {
                continue;
            }

            $lines[] = [
                'transaction' => $row,
                'account' => $account,
                'contract' => $account->contract_amount,
                'extra' => $account->contract_amount?->minus($disbursed[$account->id]),
                'officer' => $account->party->activeAssignment?->fieldOfficer,
            ];
        }

        $issued = $this->ledger->loansIssued($from, $to);

        return ['rows' => $lines, 'count' => $issued['count'], 'principal' => $issued['principal'], 'extra' => $issued['extra']];
    }

    /**
     * Savings withdrawals in the period, oldest first.
     *
     * @return array{rows: Collection<int, Transaction>, total: Money}
     */
    public function withdrawalRegister(Carbon $from, Carbon $to): array
    {
        $rows = $this->ledger->rowsOfType([TransactionType::SavingWithdrawal], $from, $to)
            ->with(['account', 'party'])
            ->orderBy('transaction_date')->orderBy('id')
            ->get();

        return [
            'rows' => $rows,
            'total' => Money::fromPoisha((int) $rows->sum(fn (Transaction $row): int => $row->amount->poisha)),
        ];
    }

    /**
     * One row per day of the period: money in and out by type, the day's net and the running cash
     * figure. Opening is everything received minus paid out before the first day (all payment
     * methods, book entries excluded). Same figures as the old daily cash sheet, from the ledger.
     *
     * @return array{
     *     opening: Money, closing: Money, totals: array<string, Money>,
     *     days: list<array{date: Carbon, office_expense: Money, office_income: Money, loan_collection: Money, loan_disbursement: Money, saving_deposit: Money, saving_withdrawal: Money, investment_deposit: Money, investment_return: Money, net: Money, running: Money}>
     * }
     */
    public function dailyCashBook(Carbon $from, Carbon $to): array
    {
        $perDay = $this->ledger->totalsByTypePerDay($from, $to);
        $zero = Money::zero();
        $opening = $this->cashBookOpening($from);
        $running = $opening;
        $totals = array_fill_keys(self::CASH_BOOK_TYPES, $zero);
        $days = [];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $amounts = $perDay[$date->toDateString()] ?? [];
            $line = ['date' => $date->copy()];
            foreach (self::CASH_BOOK_TYPES as $type) {
                $line[$type] = $amounts[$type] ?? $zero;
                $totals[$type] = $totals[$type]->plus($line[$type]);
            }

            $line['net'] = $this->moneyIn($amounts)->minus($this->moneyOut($amounts));
            $running = $running->plus($line['net']);
            $line['running'] = $running;
            $days[] = $line;
        }

        return ['opening' => $opening, 'closing' => $running, 'totals' => $totals, 'days' => $days];
    }

    private const CASH_BOOK_TYPES = ['office_expense', 'office_income', 'loan_collection', 'loan_disbursement', 'saving_deposit', 'saving_withdrawal', 'investment_deposit', 'investment_return'];

    /**
     * Opening office cash plus received minus paid out on every day before $from (nothing is dated before the opening day).
     */
    private function cashBookOpening(Carbon $from): Money
    {
        $before = $this->ledger->totalsByType(null, $from->copy()->subDay());

        return $this->ledger->openingCash()->plus($this->moneyIn($before))->minus($this->moneyOut($before));
    }

    /**
     * @param  array<string, Money>  $totals  amounts keyed by transaction type value
     */
    private function moneyIn(array $totals): Money
    {
        return Money::fromPoisha(array_sum(array_map(
            fn (string $type): int => ($totals[$type] ?? Money::zero())->poisha,
            [...array_map(fn (TransactionType $type): string => $type->value, TransactionType::collectionTypes()), 'office_income'],
        )));
    }

    /**
     * @param  array<string, Money>  $totals  amounts keyed by transaction type value
     */
    private function moneyOut(array $totals): Money
    {
        return Money::fromPoisha(array_sum(array_map(
            fn (string $type): int => ($totals[$type] ?? Money::zero())->poisha,
            ['loan_disbursement', 'saving_withdrawal', 'investment_return', 'office_expense'],
        )));
    }

    /**
     * One Dhaka month: ledger totals by type, expected vs collected on the month's schedule rows,
     * and what was received each day (for the chart).
     *
     * @return array{
     *     totals: array<string, Money>, received: Money, paid_out: Money, office_net: Money,
     *     money_in: Money, money_out: Money, opening: Money, closing: Money,
     *     loans: array{count: int, principal: Money, extra: Money},
     *     heads: list<array{head: OfficeHead|null, kind: TransactionType, total: Money}>,
     *     expected: Money, collected: Money, rate: int, counts: array<string, int>,
     *     daily: array<string, Money>
     * }
     */
    public function monthly(Carbon $month): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth()->startOfDay();

        $totals = $this->ledger->totalsByType($from, $to);
        $zero = Money::zero();
        $get = fn (TransactionType $type): Money => $totals[$type->value] ?? $zero;

        [$rows, $collected] = $this->scheduleRows($from, $to);
        $schedule = $this->scheduleTotals($rows, $collected);
        $counted = (int) $rows->sum(fn (CollectionSchedule $row): int => min($collected[$row->id]->poisha, $row->expected_amount->poisha));

        $daily = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $daily[$day->toDateString()] = $zero;
        }

        $moneyIn = $this->moneyIn($totals);
        $moneyOut = $this->moneyOut($totals);
        $opening = $this->cashBookOpening($from);

        return [
            'totals' => $totals,
            'received' => $this->received($totals),
            'paid_out' => $this->paidOut($totals),
            'office_net' => $get(TransactionType::OfficeIncome)->minus($get(TransactionType::OfficeExpense)),
            'money_in' => $moneyIn,
            'money_out' => $moneyOut,
            'opening' => $opening,
            'closing' => $opening->plus($moneyIn)->minus($moneyOut),
            'loans' => $this->ledger->loansIssued($from, $to),
            'heads' => $this->ledger->officeTotalsByHead($from, $to),
            'expected' => $schedule['expected'],
            'collected' => $schedule['collected'],
            'rate' => $schedule['expected']->isPositive() ? (int) round($counted * 100 / $schedule['expected']->poisha) : 0,
            'counts' => $schedule['counts'],
            'daily' => array_replace($daily, $this->ledger->receivedPerDay($from, $to)),
        ];
    }

    /**
     * Where the money stands now: cash (office and with officers), what members owe on loans, what
     * the NGO holds for members (savings, FDR payback), and this month's movement up to `$today`.
     *
     * @return array{
     *     cash: array{office: Money, officers: Money, total: Money},
     *     owed_to_us: array{loans: Money, overdue: Money, count: int},
     *     we_owe: array{savings: Money, fdr: Money, total: Money},
     *     month: array{from: Carbon, received: Money, paid_out: Money, office_income: Money, office_expense: Money}
     * }
     */
    public function position(Carbon $today): array
    {
        $office = $this->ledger->officeCashBalance($today);
        $officers = $this->ledger->totalCashWithOfficers();
        $loans = $this->outstanding($today);
        $savings = $this->ledger->totalSavingBalance();
        $fdr = $this->ledger->totalFdrRemaining();
        $from = $today->copy()->startOfMonth();
        $totals = $this->ledger->totalsByType($from, $today);

        return [
            'cash' => ['office' => $office, 'officers' => $officers, 'total' => $office->plus($officers)],
            'owed_to_us' => ['loans' => $loans['outstanding'], 'overdue' => $loans['overdue'], 'count' => count($loans['lines'])],
            'we_owe' => ['savings' => $savings, 'fdr' => $fdr, 'total' => $savings->plus($fdr)],
            'month' => [
                'from' => $from,
                'received' => $this->received($totals),
                'paid_out' => $this->paidOut($totals),
                'office_income' => $totals[TransactionType::OfficeIncome->value] ?? Money::zero(),
                'office_expense' => $totals[TransactionType::OfficeExpense->value] ?? Money::zero(),
            ],
        ];
    }

    /**
     * Money received from members (collections, saving and FDR deposits) in a totals-by-type map.
     *
     * @param  array<string, Money>  $totals
     */
    private function received(array $totals): Money
    {
        return Money::fromPoisha(array_sum(array_map(
            fn (TransactionType $type): int => ($totals[$type->value] ?? Money::zero())->poisha,
            TransactionType::collectionTypes(),
        )));
    }

    /**
     * Money paid out to members (loans given, withdrawals, FDR paybacks) in a totals-by-type map.
     *
     * @param  array<string, Money>  $totals
     */
    private function paidOut(array $totals): Money
    {
        return Money::fromPoisha(array_sum(array_map(
            fn (TransactionType $type): int => ($totals[$type->value] ?? Money::zero())->poisha,
            [TransactionType::LoanDisbursement, TransactionType::SavingWithdrawal, TransactionType::InvestmentReturn],
        )));
    }

    /**
     * Progress of each of a member's accounts, figures batched over all of them. Loan: given, agreed
     * extra, received, remaining, installments paid of about how many, estimated end. Saving: saved,
     * balance, agreed. FDR: held, payback remaining. Every account: installment, next due date (open
     * rows from today), last payment, missed rows. `remaining` equals LedgerService::remainingBalance().
     *
     * @param  EloquentCollection<int, Account>  $accounts
     * @return array<int, array{
     *     remaining: Money, contract: Money|null, installment: Money|null, next_due: Carbon|null,
     *     last_paid: Carbon|null, paid: int, missed: int, given: Money|null, extra: Money|null,
     *     received: Money|null, total: int|null, ends_on: Carbon|null, saved: Money|null, held: Money|null
     * }>
     */
    public function memberAccounts(EloquentCollection $accounts, Carbon $today): array
    {
        $accounts->loadMissing('collectionPlan');
        $ids = $accounts->modelKeys();
        $sums = $this->ledger->sumsByTypeFor($ids);
        $lastPaid = $this->ledger->lastCollectionDateFor($ids);
        $schedules = CollectionSchedule::query()
            ->whereIn('account_id', $ids)
            ->where('status', '!=', ScheduleStatus::Cancelled)
            ->get(['id', 'account_id', 'due_date', 'status'])
            ->groupBy('account_id');

        $result = [];
        foreach ($accounts as $account) {
            $get = fn (array $types): Money => Money::fromPoisha(array_sum(array_map(
                fn (TransactionType $type): int => ($sums[$account->id][$type->value] ?? Money::zero())->poisha,
                $types,
            )));
            $rows = $schedules->get($account->id, collect());
            $plan = $account->collectionPlan;

            $line = [
                'contract' => $account->contract_amount,
                'installment' => $plan?->installment_amount,
                'next_due' => $rows
                    ->filter(fn (CollectionSchedule $row): bool => in_array($row->status, [ScheduleStatus::Pending, ScheduleStatus::PartiallyPaid], true)
                        && $row->due_date->toDateString() >= $today->toDateString())
                    ->min('due_date'),
                'last_paid' => $lastPaid[$account->id] ?? null,
                'paid' => $rows->where('status', ScheduleStatus::Paid)->count(),
                'missed' => $rows->where('status', ScheduleStatus::Missed)->count(),
                'given' => null, 'extra' => null, 'received' => null, 'total' => null, 'ends_on' => null, 'saved' => null, 'held' => null,
            ];

            if ($account->isLoan()) {
                $given = $get(LedgerService::LOAN_DISBURSED);
                $received = $get(LedgerService::LOAN_RECEIVED);
                $remaining = ($account->contract_amount ?? Money::zero())->minus($received);
                $left = $plan !== null && $remaining->isPositive() ? Installments::ofAmount($remaining, $plan->installment_amount)->count : 0;
                $dates = $left > 0 ? $this->collections->projectDates($plan, $today, $left) : [];
                $line = [
                    'remaining' => $remaining,
                    'given' => $given,
                    'extra' => $account->contract_amount !== null && $given->isPositive() ? $account->contract_amount->minus($given) : null,
                    'received' => $received,
                    'total' => $plan !== null ? $line['paid'] + $left : null,
                    'ends_on' => $dates === [] ? null : end($dates),
                ] + $line;
            } elseif ($account->isFdr()) {
                $deposited = $get([TransactionType::InvestmentDeposit]);
                $returned = $get([TransactionType::InvestmentReturn]);
                $line = [
                    'remaining' => ($account->contract_amount ?? $deposited)->minus($returned),
                    'held' => $deposited->minus($returned),
                ] + $line;
            } else {
                $saved = $get(TransactionType::savingInTypes());
                $line = ['remaining' => $saved->minus($get([TransactionType::SavingWithdrawal])), 'saved' => $saved] + $line;
            }

            $result[$account->id] = $line;
        }

        return $result;
    }
}
