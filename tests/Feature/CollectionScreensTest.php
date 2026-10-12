<?php

namespace Tests\Feature;

use App\Enums\PlanFrequency;
use App\Enums\Role;
use App\Models\Account;
use App\Models\CollectionPlan;
use App\Models\Holiday;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CollectionScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $accountant;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
        $this->manager = User::factory()->withRole(Role::Manager)->create();
        $this->accountant = User::factory()->withRole(Role::Accountant)->create();
        $this->account = Account::factory()->create(['opened_at' => '2026-10-01']);
    }

    public function test_manager_saves_a_daily_plan_and_sees_upcoming_rows(): void
    {
        $this->actingAs($this->manager)
            ->put(route('admin.accounts.collection-plan.update', $this->account), [
                'frequency' => 'daily',
                'installment_amount' => '১৫০',
                'weekdays' => ['0', '1', '2'],
                'starts_on' => '2026-10-12',
            ])
            ->assertRedirect(route('admin.accounts.show', $this->account))
            ->assertSessionHasNoErrors();

        $plan = CollectionPlan::sole();
        $this->assertSame(PlanFrequency::Daily, $plan->frequency);
        $this->assertSame([0, 1, 2], $plan->weekdays);
        $this->assertSame(15000, $plan->installment_amount->poisha);
        $this->assertSame(2, $this->account->schedules()->count());

        $this->actingAs($this->manager)->get(route('admin.accounts.show', $this->account))
            ->assertOk()
            ->assertSee(__('app.schedules.upcoming'))
            ->assertSee('১৩/১০/২০২৬');
    }

    public function test_plan_duration_sets_the_end_date_and_the_estimated_total_is_shown(): void
    {
        $this->actingAs($this->manager)
            ->put(route('admin.accounts.collection-plan.update', $this->account), [
                'frequency' => 'monthly',
                'installment_amount' => '1000',
                'starts_on' => '2026-10-12',
                'duration' => '3',
                'duration_unit' => 'years',
            ])
            ->assertSessionHasNoErrors();

        $plan = CollectionPlan::sole();
        $this->assertSame('2029-10-11', $plan->ends_on?->toDateString());
        $this->assertSame(3_600_000, $plan->estimatedTotal()->poisha);

        $this->actingAs($this->manager)->get(route('admin.accounts.show', $this->account))
            ->assertOk()
            ->assertSee(Money::fromTaka('36000')->format());
    }

    public function test_an_entered_end_date_wins_over_the_duration(): void
    {
        $this->actingAs($this->manager)
            ->put(route('admin.accounts.collection-plan.update', $this->account), [
                'frequency' => 'daily',
                'installment_amount' => '100',
                'weekdays' => ['0', '1', '2', '3', '4', '5', '6'],
                'starts_on' => '2026-10-12',
                'ends_on' => '2026-10-21',
                'duration' => '2',
                'duration_unit' => 'months',
            ])
            ->assertSessionHasNoErrors();

        $plan = CollectionPlan::sole();
        $this->assertSame('2026-10-21', $plan->ends_on?->toDateString());
        $this->assertSame(100_000, $plan->estimatedTotal()->poisha);
    }

    public function test_duration_input_is_validated(): void
    {
        $this->actingAs($this->manager)
            ->put(route('admin.accounts.collection-plan.update', $this->account), [
                'frequency' => 'weekly',
                'installment_amount' => '100',
                'starts_on' => '2026-10-12',
                'duration' => '0',
                'duration_unit' => 'decades',
            ])
            ->assertSessionHasErrors(['duration', 'duration_unit']);
    }

    public function test_plan_input_is_validated(): void
    {
        $this->actingAs($this->manager)
            ->put(route('admin.accounts.collection-plan.update', $this->account), [
                'frequency' => 'daily',
                'installment_amount' => '0',
                'starts_on' => '2026-10-11',
                'ends_on' => '2026-10-01',
            ])
            ->assertSessionHasErrors(['installment_amount', 'weekdays', 'starts_on', 'ends_on']);

        $this->assertSame(0, CollectionPlan::count());
    }

    public function test_only_account_managers_can_save_a_plan(): void
    {
        $this->actingAs($this->accountant)
            ->put(route('admin.accounts.collection-plan.update', $this->account), [
                'frequency' => 'weekly',
                'installment_amount' => '500',
                'starts_on' => '2026-10-12',
            ])
            ->assertForbidden();

        $this->actingAs($this->accountant)->get(route('admin.accounts.show', $this->account))
            ->assertOk()
            ->assertDontSee(__('app.schedules.save_plan'));
    }

    public function test_manager_adds_and_removes_holidays(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.holidays.store'), ['date' => '2026-10-20', 'name' => 'Durga Puja'])
            ->assertRedirect(route('admin.holidays.index'));

        $holiday = Holiday::sole();
        $this->assertSame('2026-10-20', $holiday->date->toDateString());

        $this->actingAs($this->manager)->get(route('admin.holidays.index'))->assertOk()->assertSee('Durga Puja');

        $this->actingAs($this->manager)
            ->post(route('admin.holidays.store'), ['date' => '2026-10-20', 'name' => 'Again'])
            ->assertSessionHasErrors('date');

        $this->actingAs($this->manager)->delete(route('admin.holidays.destroy', $holiday))->assertRedirect();
        $this->assertModelMissing($holiday);
    }

    public function test_holiday_screens_need_schedule_permission(): void
    {
        $holiday = Holiday::factory()->create(['date' => '2026-10-20']);

        $this->actingAs($this->accountant)->get(route('admin.holidays.index'))->assertForbidden();
        $this->actingAs($this->accountant)->post(route('admin.holidays.store'), ['date' => '2026-10-21', 'name' => 'X'])->assertForbidden();
        $this->actingAs($this->accountant)->delete(route('admin.holidays.destroy', $holiday))->assertForbidden();
    }
}
