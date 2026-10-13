<?php

namespace Tests\Feature;

use App\Enums\ActiveStatus;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\OfficeHead;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\Money;
use Database\Seeders\OfficeHeadsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OfficeHeadsTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private User $manager;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->accountant = User::factory()->withRole(Role::Accountant)->create();
        $this->manager = User::factory()->withRole(Role::Manager)->create();
        $this->today = today(config('app.display_timezone'))->toDateString();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postOffice(User $actor, array $data): TestResponse
    {
        return $this->actingAs($actor)->post(route('admin.office-entries.store'), $data + [
            'type' => 'office_income', 'amount' => '500', 'transaction_date' => $this->today,
            'payment_method' => 'cash', 'description' => 'Admission fee',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postClientIncome(User $actor, Account $account, array $data): TestResponse
    {
        return $this->actingAs($actor)->post(route('admin.accounts.office-income.store', $account), $data + [
            'amount' => '300', 'transaction_date' => $this->today, 'payment_method' => 'cash',
        ]);
    }

    public function test_heads_are_created_and_changed_by_office_staff_only(): void
    {
        $this->actingAs($this->accountant)->post(route('admin.office-heads.store'), [
            'name' => 'ভর্তি ফি', 'kind' => 'office_income', 'status' => 'active',
        ])->assertRedirect(route('admin.office-heads.index'));

        $head = OfficeHead::sole();
        $this->assertSame(TransactionType::OfficeIncome, $head->kind);

        $this->actingAs($this->accountant)->put(route('admin.office-heads.update', $head), [
            'name' => 'ভর্তি ফি', 'kind' => 'office_income', 'status' => 'inactive',
        ])->assertRedirect(route('admin.office-heads.index'));
        $this->assertSame(ActiveStatus::Inactive, $head->fresh()?->status);

        $this->actingAs($this->accountant)->post(route('admin.office-heads.store'), ['name' => 'ভর্তি ফি', 'kind' => 'office_income', 'status' => 'active'])
            ->assertSessionHasErrors('name');
        $this->actingAs($this->accountant)->post(route('admin.office-heads.store'), ['name' => 'X', 'kind' => 'loan_collection', 'status' => 'active'])
            ->assertSessionHasErrors('kind');
        $this->actingAs($this->manager)->get(route('admin.office-heads.index'))->assertForbidden();
    }

    public function test_seeder_is_idempotent_and_covers_both_kinds(): void
    {
        $this->seed(OfficeHeadsSeeder::class);
        $this->seed(OfficeHeadsSeeder::class);

        $this->assertSame(4, OfficeHead::where('kind', TransactionType::OfficeIncome)->count());
        $this->assertSame(4, OfficeHead::where('kind', TransactionType::OfficeExpense)->count());
    }

    public function test_office_entry_can_carry_a_head_of_the_same_kind(): void
    {
        $income = OfficeHead::factory()->create();
        $expense = OfficeHead::factory()->expense()->create();

        $this->postOffice($this->accountant, ['office_head_id' => $income->id])->assertSessionHasNoErrors();
        $this->postOffice($this->accountant, ['office_head_id' => $expense->id])->assertSessionHasErrors('office_head_id');
        $this->postOffice($this->accountant, ['office_head_id' => OfficeHead::factory()->inactive()->create()->id])->assertSessionHasErrors('office_head_id');
        $this->postOffice($this->accountant, ['office_head_id' => 99999])->assertSessionHasErrors('office_head_id');
        $this->postOffice($this->accountant, [])->assertSessionHasNoErrors();

        $this->assertSame([$income->id, null], Transaction::query()->orderBy('id')->pluck('office_head_id')->all());
    }

    public function test_income_from_a_client_is_linked_to_the_account_with_a_receipt_and_leaves_balances_alone(): void
    {
        $loan = Account::factory()->loan('10000')->create();
        $head = OfficeHead::factory()->create();

        $response = $this->postClientIncome($this->accountant, $loan, ['office_head_id' => $head->id, 'description' => 'Form fee']);

        $entry = Transaction::sole();
        $response->assertRedirect(route('admin.accounts.show', $loan))
            ->assertSessionHas('status', __('app.transactions.recorded_with_receipt', ['receipt' => $entry->receipt_no]));
        $this->assertSame(TransactionType::OfficeIncome, $entry->type);
        $this->assertSame($loan->id, $entry->account_id);
        $this->assertSame($loan->party_id, $entry->party_id);
        $this->assertSame($head->id, $entry->office_head_id);
        $this->assertNotNull($entry->receipt_no);
        $this->assertNull($entry->field_officer_id);

        $ledger = app(LedgerService::class);
        $this->assertSame(1_000_000, $ledger->loanOutstanding($loan)->poisha);
        $this->assertSame(0, $ledger->loanReceived($loan)->poisha);
        $this->assertSame(30_000, $ledger->officeTotals(today(config('app.display_timezone'))->startOfMonth(), today(config('app.display_timezone')))['income']->poisha);
        $this->actingAs($this->accountant)->get(route('admin.transactions.receipt', $entry))->assertOk();
    }

    public function test_client_income_rules(): void
    {
        $loan = Account::factory()->loan('10000')->create();
        $closed = Account::factory()->loan('10000')->closed()->create();
        $head = OfficeHead::factory()->create();

        $this->postClientIncome($this->accountant, $loan, [])->assertSessionHasErrors('office_head_id', null, 'officeIncome');
        $this->postClientIncome($this->accountant, $loan, ['office_head_id' => OfficeHead::factory()->expense()->create()->id])->assertSessionHasErrors('office_head_id', null, 'officeIncome');
        $this->postClientIncome($this->accountant, $closed, ['office_head_id' => $head->id])->assertSessionHasErrors('account_id', null, 'officeIncome');
        $this->postClientIncome($this->accountant, $loan, ['office_head_id' => $head->id, 'amount' => '0'])->assertSessionHasErrors('amount', null, 'officeIncome');
        $this->postClientIncome($this->manager, $loan, ['office_head_id' => $head->id])->assertForbidden();

        $this->assertSame(0, Transaction::count());
    }

    public function test_reversing_client_income_takes_it_out_of_the_totals(): void
    {
        $loan = Account::factory()->loan('10000')->create();
        $this->postClientIncome($this->accountant, $loan, ['office_head_id' => OfficeHead::factory()->create()->id])->assertSessionHasNoErrors();
        $entry = Transaction::sole();

        $this->actingAs($this->manager)->post(route('admin.transactions.reverse', $entry), ['reason' => 'Wrong client'])
            ->assertSessionHasNoErrors();

        $reversal = Transaction::where('type', TransactionType::Reversal)->sole();
        $this->assertSame($entry->office_head_id, $reversal->office_head_id);
        $day = today(config('app.display_timezone'));
        $this->assertSame(0, app(LedgerService::class)->officeTotals($day->copy()->startOfMonth(), $day)['income']->poisha);
    }

    public function test_office_page_shows_head_client_and_totals_per_head(): void
    {
        $loan = Account::factory()->loan('10000')->create();
        $fee = OfficeHead::factory()->create(['name' => 'Admission fee']);
        $bill = OfficeHead::factory()->expense()->create(['name' => 'Power bill']);
        $this->postClientIncome($this->accountant, $loan, ['office_head_id' => $fee->id, 'amount' => '300']);
        $this->postOffice($this->accountant, ['office_head_id' => $fee->id, 'amount' => '200']);
        $this->postOffice($this->accountant, ['type' => 'office_expense', 'office_head_id' => $bill->id, 'amount' => '150']);

        $this->actingAs($this->accountant)->get(route('admin.office-entries.index'))->assertOk()
            ->assertSeeText('Admission fee')
            ->assertSeeText('Power bill')
            ->assertSeeText($loan->party->name)
            ->assertSee(route('admin.accounts.show', $loan), false)
            ->assertSeeText(Money::fromTaka('500')->format())
            ->assertSeeText(Money::fromTaka('150')->format());
    }

    public function test_account_page_offers_the_client_income_form_and_lists_the_fees(): void
    {
        $loan = Account::factory()->loan('10000')->create();
        $head = OfficeHead::factory()->create(['name' => 'Form fee']);
        $action = route('admin.accounts.office-income.store', $loan);
        $this->postClientIncome($this->accountant, $loan, ['office_head_id' => $head->id]);

        $this->actingAs($this->accountant)->get(route('admin.accounts.show', $loan))->assertOk()
            ->assertSee($action, false)->assertSeeText('Form fee');
        $this->actingAs($this->manager)->get(route('admin.accounts.show', $loan))->assertOk()->assertDontSee($action, false);
    }
}
