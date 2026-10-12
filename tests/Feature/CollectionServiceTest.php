<?php

namespace Tests\Feature;

use App\Enums\PlanFrequency;
use App\Enums\Role;
use App\Enums\ScheduleStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CollectionPlan;
use App\Models\CollectionSchedule;
use App\Models\Holiday;
use App\Models\PartyAssignment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CollectionService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CollectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private CollectionService $service;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->service = app(CollectionService::class);
        $this->manager = User::factory()->withRole(Role::Manager)->create();
        // Monday 2026-10-12 in Dhaka.
        Carbon::setTestNow('2026-10-12 06:00:00');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function plan(array $attributes = [], ?Account $account = null): CollectionPlan
    {
        return CollectionPlan::factory()->create([
            'account_id' => $account ?? Account::factory()->create(['opened_at' => '2026-01-01']),
            'starts_on' => '2026-10-01',
            ...$attributes,
        ]);
    }

    /**
     * Dates generated for the plan's account when generating every day of the period.
     *
     * @return list<string>
     */
    private function generatedDates(CollectionPlan $plan, string $from, string $to): array
    {
        foreach (Carbon::parse($from)->toPeriod($to) as $date) {
            $this->service->generateFor(Carbon::parse($date));
        }

        return CollectionSchedule::where('account_id', $plan->account_id)->orderBy('due_date')->get()
            ->map(fn (CollectionSchedule $schedule) => $schedule->due_date->toDateString())->all();
    }

    public function test_daily_plan_generates_on_selected_weekdays_only(): void
    {
        // Sun..Thu, no Friday/Saturday.
        $plan = $this->plan(['weekdays' => [0, 1, 2, 3, 4]]);

        $this->assertSame(
            ['2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'],
            $this->generatedDates($plan, '2026-10-02', '2026-10-09'),
        );
    }

    public function test_daily_plan_skips_holidays(): void
    {
        $plan = $this->plan();
        Holiday::factory()->create(['date' => '2026-10-05']);

        $this->assertSame(['2026-10-04', '2026-10-06'], $this->generatedDates($plan, '2026-10-04', '2026-10-06'));
    }

    public function test_weekly_plan_repeats_on_start_weekday_and_moves_past_holidays(): void
    {
        // Thursday.
        $plan = $this->plan(['frequency' => PlanFrequency::Weekly, 'weekdays' => null, 'starts_on' => '2026-10-01']);
        Holiday::factory()->create(['date' => '2026-10-08']);
        Holiday::factory()->create(['date' => '2026-10-09']);

        $this->assertSame(['2026-10-01', '2026-10-10', '2026-10-15'], $this->generatedDates($plan, '2026-09-25', '2026-10-20'));
    }

    public function test_monthly_plan_falls_on_month_end_in_short_months(): void
    {
        $plan = $this->plan(['frequency' => PlanFrequency::Monthly, 'weekdays' => null, 'starts_on' => '2026-01-31']);

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $this->generatedDates($plan, '2026-01-01', '2026-03-31'));
    }

    public function test_nothing_generated_outside_the_plan_period(): void
    {
        $plan = $this->plan(['starts_on' => '2026-10-05', 'ends_on' => '2026-10-06']);

        $this->assertSame(['2026-10-05', '2026-10-06'], $this->generatedDates($plan, '2026-10-04', '2026-10-07'));
    }

    public function test_row_carries_party_officer_and_installment(): void
    {
        $plan = $this->plan();
        $assignment = PartyAssignment::factory()->create(['party_id' => $plan->account->party_id]);

        $this->assertSame(1, $this->service->generateFor(Carbon::parse('2026-10-12')));

        $schedule = CollectionSchedule::sole();
        $this->assertSame($plan->account->party_id, $schedule->party_id);
        $this->assertSame($assignment->field_officer_id, $schedule->field_officer_id);
        $this->assertSame(10000, $schedule->expected_amount->poisha);
        $this->assertSame(ScheduleStatus::Pending, $schedule->status);
        $this->assertSame('schedule.generated', AuditLog::sole()->action);
        $this->assertNull(AuditLog::sole()->user_id);
    }

    public function test_unassigned_party_gets_a_row_without_officer(): void
    {
        $this->plan();

        $this->service->generateFor(Carbon::parse('2026-10-12'));

        $this->assertNull(CollectionSchedule::sole()->field_officer_id);
    }

    public function test_rerun_for_the_same_date_creates_nothing(): void
    {
        $this->plan();

        $this->assertSame(1, $this->service->generateFor(Carbon::parse('2026-10-12')));
        $this->assertSame(0, $this->service->generateFor(Carbon::parse('2026-10-12')));

        $this->assertSame(1, CollectionSchedule::count());
        $this->assertSame(1, AuditLog::count());
    }

    public function test_closed_account_gets_no_rows(): void
    {
        $this->plan([], Account::factory()->closed()->create(['opened_at' => '2026-01-01']));

        $this->assertSame(0, $this->service->generateFor(Carbon::parse('2026-10-12')));
    }

    public function test_loan_row_is_capped_at_outstanding_and_stops_when_settled(): void
    {
        $loan = Account::factory()->loan('1000')->create(['opened_at' => '2026-01-01']);
        $this->plan(['installment_amount' => Money::fromTaka('300')], $loan);
        Transaction::factory()->of($loan, TransactionType::LoanCollection, '800')->create();

        $this->service->generateFor(Carbon::parse('2026-10-12'));
        $this->assertSame(20000, CollectionSchedule::sole()->expected_amount->poisha);

        Transaction::factory()->of($loan, TransactionType::LoanCollection, '200')->create();
        $this->assertSame(0, $this->service->generateFor(Carbon::parse('2026-10-13')));
    }

    public function test_save_plan_creates_audits_and_generates_today_and_tomorrow(): void
    {
        $account = Account::factory()->create(['opened_at' => '2026-01-01']);

        $plan = $this->service->savePlan($account, PlanFrequency::Daily, Money::fromTaka('50'), CollectionPlan::DEFAULT_WEEKDAYS,
            Carbon::parse('2026-10-12'), null, $this->manager);

        $this->assertSame($this->manager->id, $plan->created_by);
        $this->assertSame(['2026-10-12', '2026-10-13'], $account->schedules()->orderBy('due_date')->pluck('due_date')
            ->map(fn (Carbon $date) => $date->toDateString())->all());
        $this->assertSame('collection_plan.saved', AuditLog::where('auditable_type', $plan->getMorphClass())->sole()->action);
    }

    public function test_save_plan_updates_the_existing_plan(): void
    {
        $plan = $this->plan();

        $saved = $this->service->savePlan($plan->account, PlanFrequency::Weekly, Money::fromTaka('700'), null,
            Carbon::parse('2026-10-20'), Carbon::parse('2026-12-31'), $this->manager);

        $this->assertSame($plan->id, $saved->id);
        $this->assertSame(1, CollectionPlan::count());
        $this->assertSame(PlanFrequency::Weekly, $saved->frequency);
        $this->assertNull($saved->weekdays);
        $this->assertSame(70000, $saved->installment_amount->poisha);
    }

    public function test_save_plan_rejects_closed_account_and_past_start(): void
    {
        $closed = Account::factory()->closed()->create();
        $active = Account::factory()->create();

        foreach ([
            [$closed, '2026-10-12', [1]],
            [$active, '2026-10-11', [1]],
            [$active, '2026-10-12', []],
        ] as [$account, $startsOn, $weekdays]) {
            $this->expectValidation(fn () => $this->service->savePlan($account, PlanFrequency::Daily, Money::fromTaka('50'),
                $weekdays, Carbon::parse($startsOn), null, $this->manager));
        }

        $this->assertSame(0, CollectionPlan::count());
    }

    public function test_command_generates_for_tomorrow_by_default_or_the_given_date(): void
    {
        $this->plan();

        $this->artisan('schedules:generate')->assertSuccessful();
        $this->artisan('schedules:generate', ['--date' => '2026-10-15'])->assertSuccessful();
        $this->artisan('schedules:generate', ['--date' => '15/10/2026'])->assertFailed();

        $this->assertSame(['2026-10-13', '2026-10-15'], CollectionSchedule::orderBy('due_date')->pluck('due_date')
            ->map(fn (Carbon $date) => $date->toDateString())->all());
    }

    public function test_adding_a_holiday_cancels_pending_rows_and_shifts_weekly_plans(): void
    {
        // Tuesday plan; tomorrow's row is already generated.
        $plan = $this->plan(['frequency' => PlanFrequency::Weekly, 'weekdays' => null, 'starts_on' => '2026-10-13']);
        $this->service->generateFor(Carbon::parse('2026-10-13'));

        $this->service->addHoliday(Carbon::parse('2026-10-13'), 'Puja', $this->manager);

        $this->assertSame(ScheduleStatus::Cancelled, CollectionSchedule::sole()->status);
        $this->assertSame(['2026-10-13', '2026-10-14'], $this->generatedDates($plan, '2026-10-14', '2026-10-14'));
        $this->assertSame(1, AuditLog::where('action', 'holiday.added')->count());
        $this->assertSame(1, AuditLog::where('action', 'schedule.cancelled')->count());
    }

    public function test_holidays_cannot_be_added_in_the_past_and_only_future_ones_removed(): void
    {
        $this->expectValidation(fn () => $this->service->addHoliday(Carbon::parse('2026-10-11'), 'Late', $this->manager));
        $this->expectValidation(fn () => $this->service->removeHoliday(Holiday::factory()->create(['date' => '2026-10-12']), $this->manager));

        $future = Holiday::factory()->create(['date' => '2026-10-20']);
        $this->service->removeHoliday($future, $this->manager);

        $this->assertModelMissing($future);
        $this->assertSame('holiday.removed', AuditLog::sole()->action);
    }

    private function expectValidation(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a validation error.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
