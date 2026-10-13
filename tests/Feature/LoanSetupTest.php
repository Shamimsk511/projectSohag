<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PlanFrequency;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\CollectionPlan;
use App\Models\CollectionSchedule;
use App\Models\Holiday;
use App\Models\Party;
use App\Models\PartyAssignment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AssignmentService;
use App\Services\CollectionService;
use App\Services\LoanSetupService;
use App\Support\CollectionPlanData;
use App\Support\Installments;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoanSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $officer;

    private Party $party;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        // Monday 2026-10-12, noon in Dhaka.
        Carbon::setTestNow('2026-10-12 06:00:00');
        $this->manager = User::factory()->withRole(Role::Manager)->create();
        $this->officer = User::factory()->withRole(Role::FieldOfficer)->create();
        $this->party = Party::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function open(array $data): TestResponse
    {
        return $this->actingAs($this->manager)->post(route('admin.parties.accounts.store', $this->party), $data + [
            'account_type' => 'loan',
            'principal_amount' => '10000',
            'extra_amount' => '2000',
            'payment_method' => 'cash',
            'opened_at' => '2026-10-12',
        ]);
    }

    public function test_one_form_gives_the_loan_assigns_the_officer_and_starts_collecting(): void
    {
        $this->open([
            'field_officer_id' => $this->officer->id,
            'frequency' => 'daily',
            'installment_amount' => '500',
            'weekdays' => CollectionPlan::DEFAULT_WEEKDAYS,
            'starts_on' => '2026-10-13',
        ])->assertSessionHasNoErrors();

        $account = Account::sole();
        $this->assertSame(1_200_000, $account->contract_amount?->poisha);
        $this->assertSame(TransactionType::LoanDisbursement, Transaction::sole()->type);
        $this->assertSame($this->officer->id, $this->party->activeAssignment()->value('field_officer_id'));

        $plan = $account->collectionPlan;
        $this->assertNotNull($plan);
        $this->assertSame(PlanFrequency::Daily, $plan->frequency);
        $this->assertSame(50_000, $plan->installment_amount->poisha);
        $this->assertSame('2026-10-13', $plan->starts_on->toDateString());
        $this->assertNull($plan->ends_on);

        // Tomorrow's row is generated at once, for the new officer.
        $row = CollectionSchedule::sole();
        $this->assertSame('2026-10-13', $row->due_date->toDateString());
        $this->assertSame($this->officer->id, $row->field_officer_id);
    }

    public function test_number_of_installments_sets_the_installment_rounded_up_to_whole_taka(): void
    {
        $this->open([
            'principal_amount' => '10000',
            'extra_amount' => '',
            'frequency' => 'weekly',
            'installment_count' => '3',
            'installment_basis' => 'count',
            'installment_amount' => '999',
            'starts_on' => '2026-10-13',
        ])->assertSessionHasNoErrors();

        $this->assertSame(333_400, Account::sole()->collectionPlan?->installment_amount->poisha);
    }

    public function test_without_installment_no_plan_is_made_and_the_officer_is_still_assigned(): void
    {
        $this->open(['field_officer_id' => $this->officer->id, 'frequency' => 'daily'])->assertSessionHasNoErrors();

        $this->assertNull(Account::sole()->collectionPlan);
        $this->assertSame(1, PartyAssignment::count());
    }

    public function test_keeping_the_current_officer_adds_no_assignment(): void
    {
        app(AssignmentService::class)->assign($this->party, $this->officer, $this->manager);

        $this->open(['field_officer_id' => $this->officer->id, 'installment_amount' => '500', 'frequency' => 'daily'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PartyAssignment::count());
        $this->assertSame('2026-10-13', Account::sole()->collectionPlan?->starts_on->toDateString());
    }

    public function test_officer_must_be_an_active_field_officer(): void
    {
        $this->open(['field_officer_id' => $this->manager->id])->assertSessionHasErrors('field_officer_id');

        $this->assertSame(0, Account::count());
    }

    public function test_a_failing_step_rolls_back_the_account_disbursement_and_assignment(): void
    {
        $service = app(LoanSetupService::class);
        $accounts = app(AccountService::class);

        try {
            $service->open(
                fn (): Account => $accounts->openLoan($this->party, Money::fromTaka('10000'), Money::zero(), PaymentMethod::Cash, today(), $this->manager),
                $this->officer,
                new CollectionPlanData(PlanFrequency::Daily, Money::fromTaka('500'), [], Carbon::parse('2026-10-13')),
                $this->manager,
            );
            $this->fail('Expected the empty weekday list to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('weekdays', $exception->errors());
        }

        $this->assertSame(0, Account::count());
        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, PartyAssignment::count());
    }

    public function test_carried_in_loan_counts_installments_on_what_is_left(): void
    {
        $admin = User::factory()->withRole(Role::Admin)->create();

        $this->actingAs($admin)->post(route('admin.parties.accounts.store', $this->party), [
            'account_type' => 'loan',
            'existing_loan' => '1',
            'principal_amount' => '10000',
            'extra_amount' => '2000',
            'received_amount' => '9000',
            'carried_as_of' => '2026-10-10',
            'opened_at' => '2026-06-01',
            'frequency' => 'weekly',
            'installment_count' => '4',
            'installment_basis' => 'count',
            'starts_on' => '2026-10-14',
        ])->assertSessionHasNoErrors();

        $this->assertSame(75_000, Account::sole()->collectionPlan?->installment_amount->poisha);
    }

    public function test_installment_math(): void
    {
        $byAmount = Installments::ofAmount(Money::fromTaka('10000'), Money::fromTaka('3000'));
        $this->assertSame([4, 100_000], [$byAmount->count, $byAmount->last()->poisha]);

        $byCount = Installments::ofCount(Money::fromTaka('10000'), 3);
        $this->assertSame([333_400, 3, 333_200], [$byCount->installment->poisha, $byCount->count, $byCount->last()->poisha]);

        // Rounding up to whole taka can need fewer installments than asked for.
        $fewer = Installments::ofCount(Money::fromTaka('10'), 7);
        $this->assertSame([200, 5, 200], [$fewer->installment->poisha, $fewer->count, $fewer->last()->poisha]);
    }

    public function test_projected_dates_skip_friday_and_holidays_like_the_generator(): void
    {
        Holiday::factory()->create(['date' => '2026-10-17']);
        Holiday::factory()->create(['date' => '2026-10-20']);
        $collections = app(CollectionService::class);

        $daily = new CollectionPlan(['frequency' => PlanFrequency::Daily, 'weekdays' => CollectionPlan::DEFAULT_WEEKDAYS, 'starts_on' => '2026-10-15']);
        $this->assertSame(['2026-10-15', '2026-10-18', '2026-10-19'], $this->dates($collections->projectDates($daily, Carbon::parse('2026-10-12'), 3)));

        $weekly = new CollectionPlan(['frequency' => PlanFrequency::Weekly, 'starts_on' => '2026-10-13']);
        $this->assertSame(['2026-10-13', '2026-10-21', '2026-10-27'], $this->dates($collections->projectDates($weekly, Carbon::parse('2026-10-12'), 3)));

        // The generator, run day by day, lands on the same dates.
        $plan = CollectionPlan::factory()->weekly()->create([
            'account_id' => Account::factory()->create(['opened_at' => '2026-10-01']),
            'starts_on' => '2026-10-13',
        ]);
        for ($day = Carbon::parse('2026-10-12'); $day->lte(Carbon::parse('2026-10-27')); $day->addDay()) {
            $collections->generateFor($day, $plan);
        }
        $this->assertSame(['2026-10-13', '2026-10-21', '2026-10-27'], $this->dates(
            CollectionSchedule::query()->orderBy('due_date')->get()->map(fn (CollectionSchedule $row): Carbon => $row->due_date)->all(),
        ));
    }

    public function test_preview_returns_installments_and_estimated_last_date(): void
    {
        $this->actingAs($this->manager)->getJson(route('admin.loans.preview', [
            'principal_amount' => '1000',
            'extra_amount' => '200',
            'installment_amount' => '500',
            'frequency' => 'weekly',
            'starts_on' => '2026-10-13',
        ]))->assertOk()->assertJson([
            'ready' => true,
            'count' => 3,
            'installment_taka' => '500.00',
            'contract' => Money::fromTaka('1200')->format(),
            'last_installment' => Money::fromTaka('200')->format(),
        ])->assertJsonPath('last_date', '২৭/১০/২০২৬');

        $this->actingAs($this->manager)->getJson(route('admin.loans.preview', ['principal_amount' => '1000']))
            ->assertOk()->assertJson(['ready' => false]);
    }

    public function test_preview_is_for_account_managers_only(): void
    {
        $accountant = User::factory()->withRole(Role::Accountant)->create();

        $this->actingAs($accountant)->getJson(route('admin.loans.preview', ['principal_amount' => '1000']))->assertForbidden();
    }

    public function test_account_page_shows_installment_progress_and_officer(): void
    {
        $this->open([
            'field_officer_id' => $this->officer->id,
            'frequency' => 'weekly',
            'installment_amount' => '4000',
            'starts_on' => '2026-10-13',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->get(route('admin.accounts.show', Account::sole()))
            ->assertOk()
            ->assertSeeText(__('app.schedules.plan_progress', ['paid' => '০', 'total' => '৩']))
            ->assertSeeText(__('app.schedules.estimated_end', ['date' => '২৭/১০/২০২৬']))
            ->assertSeeText($this->officer->name);
    }

    /**
     * @param  list<Carbon>  $dates
     * @return list<string>
     */
    private function dates(array $dates): array
    {
        return array_map(fn (Carbon $date): string => $date->toDateString(), $dates);
    }
}
