<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountAdjustment;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $admin;

    private Party $party;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->manager = User::factory()->withRole(Role::Manager)->create();
        $this->admin = User::factory()->withRole(Role::Admin)->create();
        $this->party = Party::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function open(User $actor, array $data, ?Party $party = null): TestResponse
    {
        return $this->actingAs($actor)->post(route('admin.parties.accounts.store', $party ?? $this->party), $data + [
            'opened_at' => '2026-10-01',
        ]);
    }

    public function test_numbers_follow_a_separate_sequence_per_type(): void
    {
        $this->open($this->manager, ['account_type' => 'saving'])->assertSessionHasNoErrors();
        $this->open($this->manager, ['account_type' => 'loan', 'contract_amount' => '12000'])->assertSessionHasNoErrors();
        $this->open($this->manager, ['account_type' => 'saving'])->assertSessionHasNoErrors();

        $this->assertSame(['SV-000001', 'LN-000001', 'SV-000002'], Account::orderBy('id')->pluck('account_number')->all());
    }

    public function test_opening_redirects_to_the_account_and_records_who_and_when(): void
    {
        $response = $this->open($this->manager, ['account_type' => 'loan', 'contract_amount' => '১,২৫,০০০.৫০']);

        $account = Account::sole();
        $response->assertRedirect(route('admin.accounts.show', $account));
        $this->assertSame(AccountType::Loan, $account->account_type);
        $this->assertSame(12_500_050, $account->contract_amount?->poisha);
        $this->assertSame('2026-10-01', $account->opened_at->toDateString());
        $this->assertSame($this->manager->id, $account->created_by);
        $this->assertTrue($account->isActive());
    }

    public function test_loan_needs_a_positive_contract_amount_and_saving_takes_none(): void
    {
        $this->open($this->manager, ['account_type' => 'loan'])->assertSessionHasErrors('contract_amount');
        $this->open($this->manager, ['account_type' => 'loan', 'contract_amount' => '0'])->assertSessionHasErrors('contract_amount');
        $this->open($this->manager, ['account_type' => 'loan', 'contract_amount' => 'abc'])->assertSessionHasErrors('contract_amount');
        $this->open($this->manager, ['account_type' => 'saving', 'contract_amount' => '500'])->assertSessionHasErrors('contract_amount');

        $this->assertSame(0, Account::count());
    }

    public function test_opening_date_cannot_be_in_the_future_and_party_must_be_active(): void
    {
        $this->travelTo('2026-10-12 04:00:00');

        $this->open($this->manager, ['account_type' => 'saving', 'opened_at' => '2026-10-13'])->assertSessionHasErrors('opened_at');
        $this->open($this->manager, ['account_type' => 'saving'], Party::factory()->inactive()->create())->assertSessionHasErrors('account_type');

        $this->assertSame(0, Account::count());
    }

    public function test_a_party_can_hold_several_active_loans(): void
    {
        $this->open($this->manager, ['account_type' => 'loan', 'contract_amount' => '1000'])->assertSessionHasNoErrors();
        $this->open($this->manager, ['account_type' => 'loan', 'contract_amount' => '2000'])->assertSessionHasNoErrors();

        $this->assertSame(2, $this->party->accounts()->active()->count());
    }

    public function test_only_account_managers_can_open_adjust_or_close(): void
    {
        $accountant = User::factory()->withRole(Role::Accountant)->create();
        $loan = Account::factory()->loan()->for($this->party)->create();

        $this->actingAs($accountant)->get(route('admin.parties.accounts.create', $this->party))->assertForbidden();
        $this->open($accountant, ['account_type' => 'saving'])->assertForbidden();
        $this->actingAs($accountant)->post(route('admin.accounts.adjust', $loan), ['contract_amount' => '1', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($accountant)->post(route('admin.accounts.close', $loan))->assertForbidden();
        $this->actingAs($accountant)->get(route('admin.accounts.show', $loan))->assertOk();

        $this->assertSame(1, Account::count());
        $this->assertNull($loan->fresh()?->closed_at);
    }

    public function test_adjusting_the_contract_amount_records_old_and_new_with_reason(): void
    {
        $loan = Account::factory()->loan('10000')->create();

        $this->actingAs($this->manager)->post(route('admin.accounts.adjust', $loan), [
            'contract_amount' => '11,000',
            'reason' => 'Term extended by agreement',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.accounts.show', $loan));

        $adjustment = AccountAdjustment::sole();
        $this->assertSame(1_000_000, $adjustment->old_amount->poisha);
        $this->assertSame(1_100_000, $adjustment->new_amount->poisha);
        $this->assertSame('Term extended by agreement', $adjustment->reason);
        $this->assertSame($this->manager->id, $adjustment->created_by);
        $this->assertSame(1_100_000, $loan->fresh()?->contract_amount?->poisha);
    }

    public function test_adjustment_needs_reason_a_changed_amount_and_an_active_loan(): void
    {
        $loan = Account::factory()->loan('10000')->create();
        $closed = Account::factory()->loan('10000')->closed()->create();
        $saving = Account::factory()->create();
        $adjust = fn (Account $account, array $data) => $this->actingAs($this->manager)->post(route('admin.accounts.adjust', $account), $data);

        $adjust($loan, ['contract_amount' => '12000'])->assertSessionHasErrors('reason');
        $adjust($loan, ['contract_amount' => '10000', 'reason' => 'same'])->assertSessionHasErrors('contract_amount');
        $adjust($closed, ['contract_amount' => '12000', 'reason' => 'late'])->assertSessionHasErrors('contract_amount');
        $adjust($saving, ['contract_amount' => '12000', 'reason' => 'no'])->assertSessionHasErrors('contract_amount');

        $this->assertSame(0, AccountAdjustment::count());
        $this->assertSame(1_000_000, $loan->fresh()?->contract_amount?->poisha);
    }

    public function test_empty_saving_and_fully_paid_loan_close_without_override(): void
    {
        $this->travelTo('2026-10-12 20:00:00');
        $saving = Account::factory()->create();
        $loan = Account::factory()->loan('1000')->create();
        Transaction::factory()->of($loan, TransactionType::LoanCollection, '1000')->create();

        $this->actingAs($this->manager)->post(route('admin.accounts.close', $saving))
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.accounts.show', $saving));
        $this->actingAs($this->manager)->post(route('admin.accounts.close', $loan))->assertSessionHasNoErrors();

        $saving->refresh();
        $this->assertSame('2026-10-13', $saving->closed_at?->toDateString());
        $this->assertSame($this->manager->id, $saving->closed_by);
        $this->assertNotNull($loan->fresh()?->closed_at);
    }

    public function test_closing_with_a_remaining_balance_needs_admin_and_reason(): void
    {
        $saving = Account::factory()->create();
        Transaction::factory()->of($saving, TransactionType::SavingDeposit, '50')->create();
        $loan = Account::factory()->loan('1000')->create();

        $this->actingAs($this->manager)->post(route('admin.accounts.close', $saving), ['close_reason' => 'member left'])->assertSessionHasErrors('close_reason');
        $this->actingAs($this->manager)->post(route('admin.accounts.close', $loan), ['close_reason' => 'written off'])->assertSessionHasErrors('close_reason');
        $this->actingAs($this->admin)->post(route('admin.accounts.close', $loan))->assertSessionHasErrors('close_reason');
        $this->assertNull($loan->fresh()?->closed_at);
        $this->assertNull($saving->fresh()?->closed_at);

        $this->actingAs($this->admin)->post(route('admin.accounts.close', $loan), ['close_reason' => 'Waived by committee'])->assertSessionHasNoErrors();

        $loan->refresh();
        $this->assertNotNull($loan->closed_at);
        $this->assertSame('Waived by committee', $loan->close_reason);
        $this->assertSame($this->admin->id, $loan->closed_by);
    }

    public function test_a_closed_account_cannot_be_closed_again(): void
    {
        $account = Account::factory()->closed()->create();

        $this->actingAs($this->manager)->post(route('admin.accounts.close', $account))->assertSessionHasErrors('close_reason');
    }

    public function test_account_page_shows_contract_received_outstanding_and_history(): void
    {
        $loan = Account::factory()->loan('10000')->for($this->party)->create();
        Transaction::factory()->of($loan, TransactionType::LoanCollection, '2500')->create();
        AccountAdjustment::factory()->for($loan)->create(['reason' => 'Agreed extension']);

        $this->actingAs($this->manager)->get(route('admin.accounts.show', $loan))
            ->assertOk()
            ->assertSee($loan->account_number)
            ->assertSee($this->party->name)
            ->assertSee('৳২,৫০০.০০')
            ->assertSee('৳৭,৫০০.০০')
            ->assertSee('Agreed extension')
            ->assertSee(route('admin.accounts.adjust', $loan), false)
            ->assertSee(route('admin.accounts.close', $loan), false);
    }

    public function test_party_page_lists_accounts_and_open_link(): void
    {
        $saving = Account::factory()->for($this->party)->create();

        $this->actingAs($this->manager)->get(route('admin.parties.show', $this->party))
            ->assertOk()
            ->assertSee($saving->account_number)
            ->assertSee(route('admin.accounts.show', $saving), false)
            ->assertSee(route('admin.parties.accounts.create', $this->party), false);

        $this->actingAs($this->manager)->get(route('admin.parties.accounts.create', $this->party))->assertOk();
    }
}
