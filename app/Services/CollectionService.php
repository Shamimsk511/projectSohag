<?php

namespace App\Services;

use App\Enums\PlanFrequency;
use App\Enums\ScheduleStatus;
use App\Models\Account;
use App\Models\CollectionPlan;
use App\Models\CollectionSchedule;
use App\Models\Holiday;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Only writer of collection plans and schedule rows. Rows are generated one day at a time from each
 * account's plan, so the officer on a row is whoever holds the party when it is generated.
 */
class CollectionService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create or replace the account's plan, then generate today's and tomorrow's rows so a plan that
     * starts today is not missed by the nightly run. Rows already generated stay as they are.
     *
     * @param  list<int>|null  $weekdays  collection days for a daily plan (0 = Sunday .. 6 = Saturday)
     */
    public function savePlan(
        Account $account,
        PlanFrequency $frequency,
        Money $installment,
        ?array $weekdays,
        Carbon $startsOn,
        ?Carbon $endsOn,
        User $actor,
    ): CollectionPlan {
        $today = $this->today();
        $startsOn = Carbon::parse($startsOn->toDateString());
        $endsOn = $endsOn === null ? null : Carbon::parse($endsOn->toDateString());

        if (! $account->isActive()) {
            throw ValidationException::withMessages(['frequency' => __('app.schedules.account_closed')]);
        }

        if (! $installment->isPositive()) {
            throw ValidationException::withMessages(['installment_amount' => __('app.schedules.installment_positive')]);
        }

        if ($startsOn->lt($today) || $startsOn->lt($account->opened_at)) {
            throw ValidationException::withMessages(['starts_on' => __('app.schedules.start_too_early')]);
        }

        if ($endsOn !== null && $endsOn->lt($startsOn)) {
            throw ValidationException::withMessages(['ends_on' => __('app.schedules.end_before_start')]);
        }

        if ($frequency === PlanFrequency::Daily && $weekdays === []) {
            throw ValidationException::withMessages(['weekdays' => __('app.schedules.weekdays_required')]);
        }

        $plan = DB::transaction(function () use ($account, $frequency, $installment, $weekdays, $startsOn, $endsOn, $actor): CollectionPlan {
            $plan = $account->collectionPlan()->lockForUpdate()->first() ?? new CollectionPlan(['account_id' => $account->id]);
            $isNew = ! $plan->exists;

            $plan->fill([
                'frequency' => $frequency,
                'installment_amount' => $installment,
                'weekdays' => $frequency === PlanFrequency::Daily ? array_values(array_unique($weekdays ?? CollectionPlan::DEFAULT_WEEKDAYS)) : null,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn?->toDateString(),
                'created_by' => $isNew ? $actor->id : $plan->created_by,
            ])->save();

            $isNew
                ? $this->audit->created('collection_plan.saved', $plan, $actor)
                : $this->audit->updated('collection_plan.saved', $plan, $actor);

            return $plan;
        });

        $this->generateFor($today, $plan);
        $this->generateFor($today->copy()->addDay(), $plan);

        return $plan;
    }

    /**
     * Add a one-off collection date (custom schedule) for the officer currently holding the party.
     */
    public function addSchedule(Account $account, Carbon $dueDate, Money $expected, ?string $notes, User $actor): CollectionSchedule
    {
        $dueDate = Carbon::parse($dueDate->toDateString());

        if (! $account->isActive()) {
            throw ValidationException::withMessages(['due_date' => __('app.schedules.account_closed')]);
        }

        $this->assertEditable($dueDate, $expected);

        return DB::transaction(function () use ($account, $dueDate, $expected, $notes, $actor): CollectionSchedule {
            Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $this->assertFree($account->id, $dueDate, null);

            $schedule = $account->schedules()->create([
                'party_id' => $account->party_id,
                'field_officer_id' => $account->party->activeAssignment()->value('field_officer_id'),
                'due_date' => $dueDate->toDateString(),
                'expected_amount' => $expected,
                'notes' => $notes,
            ]);
            $this->audit->created('schedule.added', $schedule, $actor);

            return $schedule;
        });
    }

    /**
     * Move a pending row to another date and/or change what is expected.
     */
    public function updateSchedule(CollectionSchedule $schedule, Carbon $dueDate, Money $expected, ?string $notes, User $actor): CollectionSchedule
    {
        $dueDate = Carbon::parse($dueDate->toDateString());
        $this->assertEditable($dueDate, $expected);

        return DB::transaction(function () use ($schedule, $dueDate, $expected, $notes, $actor): CollectionSchedule {
            $schedule = $this->lockPending($schedule);
            $this->assertFree($schedule->account_id, $dueDate, $schedule->id);

            $schedule->update(['due_date' => $dueDate->toDateString(), 'expected_amount' => $expected, 'notes' => $notes]);
            $this->audit->updated('schedule.updated', $schedule, $actor);

            return $schedule;
        });
    }

    /**
     * Cancel a pending row. The reason replaces its notes; the audit log keeps the old ones.
     */
    public function cancelSchedule(CollectionSchedule $schedule, string $reason, User $actor): CollectionSchedule
    {
        return DB::transaction(function () use ($schedule, $reason, $actor): CollectionSchedule {
            $schedule = $this->lockPending($schedule);

            $schedule->update(['status' => ScheduleStatus::Cancelled, 'notes' => $reason]);
            $this->audit->updated('schedule.cancelled', $schedule, $actor);

            return $schedule;
        });
    }

    /**
     * Hand one officer's pending rows for a day ($from null = unassigned rows) to another active
     * field officer, e.g. to cover an absence. Party assignments stay as they are. Returns the count.
     */
    public function reassignDay(Carbon $date, ?User $from, User $to, User $actor): int
    {
        $date = Carbon::parse($date->toDateString());

        if ($date->lt($this->today())) {
            throw ValidationException::withMessages(['date' => __('app.schedules.date_past')]);
        }

        if ($from?->id === $to->id || ! User::query()->activeFieldOfficers()->whereKey($to->id)->exists()) {
            throw ValidationException::withMessages(['to_officer_id' => __('app.schedules.invalid_officer')]);
        }

        return DB::transaction(function () use ($date, $from, $to, $actor): int {
            $rows = CollectionSchedule::query()
                ->dueOn($date)
                ->where('status', ScheduleStatus::Pending)
                ->where('field_officer_id', $from?->id)
                ->lockForUpdate()
                ->get();

            foreach ($rows as $schedule) {
                $schedule->update(['field_officer_id' => $to->id]);
                $this->audit->updated('schedule.reassigned', $schedule, $actor);
            }

            return $rows->count();
        });
    }

    /**
     * Add a day without collections. Pending rows already generated for it are cancelled; weekly and
     * monthly plans then fall on the next working day.
     */
    public function addHoliday(Carbon $date, string $name, User $actor): Holiday
    {
        $date = Carbon::parse($date->toDateString());

        if ($date->lt($this->today())) {
            throw ValidationException::withMessages(['date' => __('app.holidays.date_past')]);
        }

        $nextDay = $date->copy()->addDay()->toDateString();
        if (Holiday::query()->where('date', '>=', $date->toDateString())->where('date', '<', $nextDay)->exists()) {
            throw ValidationException::withMessages(['date' => __('app.holidays.duplicate')]);
        }

        return DB::transaction(function () use ($date, $name, $actor): Holiday {
            $holiday = Holiday::create(['date' => $date->toDateString(), 'name' => $name, 'created_by' => $actor->id]);
            $this->audit->created('holiday.added', $holiday, $actor);

            CollectionSchedule::query()->dueOn($date)->where('status', ScheduleStatus::Pending)->lockForUpdate()->get()
                ->each(function (CollectionSchedule $schedule) use ($actor): void {
                    $schedule->update(['status' => ScheduleStatus::Cancelled]);
                    $this->audit->updated('schedule.cancelled', $schedule, $actor);
                });

            return $holiday;
        });
    }

    /**
     * Remove a future holiday. Rows cancelled when it was added stay cancelled.
     */
    public function removeHoliday(Holiday $holiday, User $actor): void
    {
        if (! $holiday->date->gt($this->today())) {
            throw ValidationException::withMessages(['date' => __('app.holidays.not_removable')]);
        }

        DB::transaction(function () use ($holiday, $actor): void {
            $this->audit->deleted('holiday.removed', $holiday, $actor);
            $holiday->delete();
        });
    }

    /**
     * Create the schedule rows due on $date (every plan, or just $only). Existing rows are left
     * alone, so running it twice for a date is harmless. Returns the number of rows created.
     */
    public function generateFor(Carbon $date, ?CollectionPlan $only = null): int
    {
        $date = Carbon::parse($date->toDateString());
        $holidays = Holiday::query()
            ->where('date', '>=', $date->copy()->subDays(60)->toDateString())
            ->where('date', '<', $date->copy()->addDay()->toDateString())
            ->pluck('date')
            ->map(fn (Carbon $holiday): string => $holiday->toDateString())
            ->all();

        if (in_array($date->toDateString(), $holidays, true)) {
            return 0;
        }

        $plans = CollectionPlan::query()
            ->when($only, fn ($query) => $query->whereKey($only->id))
            ->where('starts_on', '<', $date->copy()->addDay()->toDateString())
            ->whereHas('account', fn ($query) => $query->whereNull('closed_at'))
            ->with('account.party.activeAssignment')
            ->get();

        $created = 0;
        foreach ($plans as $plan) {
            if ($this->isDue($plan, $date, $holidays) && $this->createRow($plan, $date)) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Daily plans skip holidays. Weekly and monthly plans whose day fell on holidays just before
     * $date are due on $date instead, so no installment is lost.
     *
     * @param  list<string>  $holidays
     */
    private function isDue(CollectionPlan $plan, Carbon $date, array $holidays): bool
    {
        if ($plan->frequency === PlanFrequency::Daily) {
            return $plan->fallsOn($date);
        }

        $candidate = $date->copy();
        do {
            if ($plan->fallsOn($candidate)) {
                return true;
            }
            $candidate->subDay();
        } while (in_array($candidate->toDateString(), $holidays, true));

        return false;
    }

    /**
     * Loans are expected only up to their outstanding amount and get no row once settled.
     */
    private function createRow(CollectionPlan $plan, Carbon $date): bool
    {
        $account = $plan->account;
        $expected = $plan->installment_amount;

        if ($account->isLoan()) {
            $outstanding = $this->ledger->loanOutstanding($account);
            if (! $outstanding->isPositive()) {
                return false;
            }
            if ($expected->greaterThan($outstanding)) {
                $expected = $outstanding;
            }
        }

        return DB::transaction(function () use ($account, $date, $expected): bool {
            if ($account->schedules()->dueOn($date)->exists()) {
                return false;
            }

            try {
                $schedule = $account->schedules()->create([
                    'party_id' => $account->party_id,
                    'field_officer_id' => $account->party->activeAssignment?->field_officer_id,
                    'due_date' => $date->toDateString(),
                    'expected_amount' => $expected,
                ]);
            } catch (UniqueConstraintViolationException) {
                return false;
            }

            $this->audit->created('schedule.generated', $schedule, null);

            return true;
        });
    }

    private function assertEditable(Carbon $dueDate, Money $expected): void
    {
        if ($dueDate->lt($this->today())) {
            throw ValidationException::withMessages(['due_date' => __('app.schedules.date_past')]);
        }

        if (! $expected->isPositive()) {
            throw ValidationException::withMessages(['expected_amount' => __('app.schedules.installment_positive')]);
        }
    }

    /**
     * One row per account per date; $ignoreId is the row being moved.
     */
    private function assertFree(int $accountId, Carbon $dueDate, ?int $ignoreId): void
    {
        $taken = CollectionSchedule::query()
            ->where('account_id', $accountId)
            ->dueOn($dueDate)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['due_date' => __('app.schedules.date_taken')]);
        }
    }

    /**
     * Fresh locked copy; only pending rows can be changed by hand.
     */
    private function lockPending(CollectionSchedule $schedule): CollectionSchedule
    {
        $schedule = CollectionSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();

        if ($schedule->status !== ScheduleStatus::Pending) {
            throw ValidationException::withMessages(['status' => __('app.schedules.not_pending')]);
        }

        return $schedule;
    }

    /**
     * Dhaka business date as a midnight in the app timezone, comparable with date-cast columns.
     */
    private function today(): Carbon
    {
        return Carbon::parse(today(config('app.display_timezone'))->toDateString());
    }
}
