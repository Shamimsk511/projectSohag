<?php

namespace Tests\Feature;

use App\Enums\PlanFrequency;
use App\Enums\Role;
use App\Models\Account;
use App\Models\CollectionPlan;
use App\Models\CollectionSchedule;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SavingFdrSetupTest extends TestCase
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

    public function test_one_form_opens_a_saving_with_agreed_amount_officer_and_plan_for_a_term(): void
    {
        $this->open('saving', [
            'contract_amount' => '36000',
            'field_officer_id' => $this->officer->id,
            'frequency' => 'daily',
            'installment_amount' => '100',
            'weekdays' => CollectionPlan::DEFAULT_WEEKDAYS,
            'starts_on' => '2026-10-13',
            'duration' => '1',
            'duration_unit' => 'years',
        ])->assertSessionHasNoErrors();

        $account = Account::sole();
        $this->assertSame(3_600_000, $account->contract_amount?->poisha);
        $this->assertSame(0, Transaction::count());
        $this->assertSame($this->officer->id, $this->party->activeAssignment()->value('field_officer_id'));

        $plan = $account->collectionPlan;
        $this->assertNotNull($plan);
        $this->assertSame(10_000, $plan->installment_amount->poisha);
        $this->assertSame('2026-10-13', $plan->starts_on->toDateString());
        $this->assertSame('2027-10-12', $plan->ends_on?->toDateString());

        $row = CollectionSchedule::sole();
        $this->assertSame('2026-10-13', $row->due_date->toDateString());
        $this->assertSame($this->officer->id, $row->field_officer_id);
    }

    public function test_typed_end_date_wins_over_the_term(): void
    {
        $this->open('saving', [
            'installment_amount' => '100',
            'starts_on' => '2026-10-13',
            'duration' => '1',
            'duration_unit' => 'years',
            'ends_on' => '2027-04-30',
        ])->assertSessionHasNoErrors();

        $this->assertSame('2027-04-30', Account::sole()->collectionPlan?->ends_on?->toDateString());
    }

    public function test_fdr_opens_with_agreed_payback_and_a_monthly_deposit_plan(): void
    {
        $this->open('fdr', [
            'contract_amount' => '13000',
            'frequency' => 'monthly',
            'installment_amount' => '1000',
            'starts_on' => '2026-11-01',
        ])->assertSessionHasNoErrors();

        $account = Account::sole();
        $this->assertTrue($account->isFdr());
        $this->assertSame(1_300_000, $account->contract_amount?->poisha);
        $plan = $account->collectionPlan;
        $this->assertNotNull($plan);
        $this->assertSame(PlanFrequency::Monthly, $plan->frequency);
        $this->assertNull($plan->weekdays);
        $this->assertNull($plan->ends_on);
    }

    public function test_without_installment_no_plan_is_made_and_the_officer_is_still_assigned(): void
    {
        $this->open('saving', ['field_officer_id' => $this->officer->id])->assertSessionHasNoErrors();

        $this->assertNull(Account::sole()->collectionPlan);
        $this->assertSame($this->officer->id, $this->party->activeAssignment()->value('field_officer_id'));
    }

    public function test_bad_plan_input_opens_nothing(): void
    {
        $this->open('saving', [
            'field_officer_id' => $this->officer->id,
            'installment_amount' => '100',
            'starts_on' => '2026-10-13',
            'ends_on' => '2026-10-01',
        ])->assertSessionHasErrors('ends_on');

        $this->assertSame(0, Account::count());
        $this->assertNull($this->party->activeAssignment()->value('field_officer_id'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function open(string $type, array $data): TestResponse
    {
        return $this->actingAs($this->manager)->post(route('admin.parties.accounts.store', $this->party), $data + [
            'account_type' => $type,
            'opened_at' => '2026-10-12',
        ]);
    }
}
