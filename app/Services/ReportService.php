<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\PaymentMethod;
use App\Enums\ScheduleStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\CashHandover;
use App\Models\CollectionSchedule;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Report figures. Everything is computed from schedules and the ledger on each call; nothing is stored.
 */
class ReportService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * Admin dashboard for one Dhaka day: expected vs collected on that day's rows, standing totals, per-officer table.
     *
     * @return array{
     *     expected: Money, collected: Money, received_today: Money,
     *     counts: array<string, int>,
     *     loan_outstanding: Money, saving_balance: Money, cash_in_hand: Money,
     *     officers: list<array{officer: User, rows: int, paid: int, partial: int, missed: int, expected: Money, collected: Money, rate: int}>
     * }
     */
    public function dashboard(Carbon $day): array
    {
        [$rows, $collected] = $this->scheduleRows($day, $day);
        $totals = $this->scheduleTotals($rows, $collected);

        $officers = $this->officerLines($rows, $collected);

        $holders = Transaction::query()->whereNotNull('field_officer_id')->distinct()->pluck('field_officer_id')->all();

        return [
            'expected' => $totals['expected'],
            'collected' => $totals['collected'],
            'received_today' => $this->ledger->receivedOn($day),
            'counts' => $totals['counts'],
            'loan_outstanding' => $this->ledger->totalLoanOutstanding(),
            'saving_balance' => $this->ledger->totalSavingBalance(),
            'cash_in_hand' => Money::fromPoisha(array_sum(array_map(
                fn (Money $money): int => $money->poisha,
                $this->ledger->cashInHand(array_map('intval', $holders)),
            ))),
            'officers' => $officers,
        ];
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
     * @return array{0: Collection<int, CollectionSchedule>, 1: array<int, Money>}
     */
    private function scheduleRows(Carbon $from, Carbon $to): array
    {
        $rows = CollectionSchedule::query()
            ->where('due_date', '>=', $from->toDateString())
            ->where('due_date', '<', $to->copy()->addDay()->toDateString())
            ->where('status', '!=', ScheduleStatus::Cancelled)
            ->get(['id', 'field_officer_id', 'expected_amount', 'status']);

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

        $rows = $schedules->map(fn (CollectionSchedule $schedule): array => [
            'schedule' => $schedule,
            'collected' => $collected[$schedule->id],
            'remaining' => $schedule->expected_amount->greaterThan($collected[$schedule->id])
                ? $schedule->expected_amount->minus($collected[$schedule->id])
                : Money::zero(),
        ])->all();

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
     * One Dhaka month: ledger totals by type, expected vs collected on the month's schedule rows,
     * and what was received each day (for the chart).
     *
     * @return array{
     *     totals: array<string, Money>, received: Money, paid_out: Money, office_net: Money,
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

        return [
            'totals' => $totals,
            'received' => $get(TransactionType::LoanCollection)->plus($get(TransactionType::SavingDeposit)),
            'paid_out' => $get(TransactionType::LoanDisbursement)->plus($get(TransactionType::SavingWithdrawal)),
            'office_net' => $get(TransactionType::OfficeIncome)->minus($get(TransactionType::OfficeExpense)),
            'expected' => $schedule['expected'],
            'collected' => $schedule['collected'],
            'rate' => $schedule['expected']->isPositive() ? (int) round($counted * 100 / $schedule['expected']->poisha) : 0,
            'counts' => $schedule['counts'],
            'daily' => array_replace($daily, $this->ledger->receivedPerDay($from, $to)),
        ];
    }
}
