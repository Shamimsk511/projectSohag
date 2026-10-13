<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountAdjustment;
use App\Models\DailyClosing;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\Money;
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
        $this->open($this->manager, ['account_type' => 'loan', 'principal_amount' => '12000'])->assertSessionHasNoErrors();
        $this->open($this->manager, ['account_type' => 'saving'])->assertSessionHasNoErrors();

        $this->assertSame(['SV-000001', 'LN-000001', 'SV-000002'], Account::orderBy('id')->pluck('account_number')->all());
    }

    public function test_opening_a_loan_disburses_principal_and_sets_contract_to_principal_plus_extra(): void
    {
        $response = $this->open($this->manager, [
            'account_type' => 'loan',
            'principal_amount' => '১,২৫,০০০.৫০',
            'extra_amount' => '20,000',
            'payment_method' => 'bank',
        ]);

        $account = Account::sole();
        $response->assertRedirect(route('admin.accounts.show', $account));
        $this->assertSame(AccountType::Loan, $account->account_type);
        $this->assertSame(14_500_050, $account->contract_amount?->poisha);
        $this->assertSame('2026-10-01', $account->opened_at->toDateString());
        $this->assertSame($this->manager->id, $account->created_by);
        $this->assertTrue($account->isActive());

        $disbursement = Transaction::sole();
        $this->assertSame(TransactionType::LoanDisbursement, $disbursement->type);
        $this->assertSame(12_500_050, $disbursement->amount->poisha);
        $this->assertSame('2026-10-01', $disbursement->transaction_date->toDateString());
        $this->assertSame(PaymentMethod::Bank, $disbursement->payment_method);
        $this->assertSame($account->id, $disbursement->account_id);

        $ledger = app(LedgerService::class);
        $this->assertSame(12_500_050, $ledger->loanDisbursed($account)->poisha);
        $this->assertSame(2_000_000, $ledger->agreedExtra($account)?->poisha);
        $this->assertSame(14_500_050, $ledger->loanOutstanding($account)->poisha);
    }

    public function test_extra_is_optional_and_defaults_to_nothing(): void
    {
        $this->open($this->manager, ['account_type' => 'loan', 'principal_amount' => '50000'])->assertSessionHasNoErrors();

        $account = Account::sole();
        $this->assertSame(5_000_000, $account->contract_amount?->poisha);
        $this->assertSame(0, app(LedgerService::class)->agreedExtra($account)?->poisha);
    }

    public function test_agreed_extra_is_unknown_for_a_loan_without_a_disbursement(): void
    {
        $loan = Account::factory()->loan('10000')->create();

        $this->assertNull(app(LedgerService::class)->agreedExtra($loan));
    }

    public function test_a_rejected_disbursement_leaves_no_account_behind(): void
    {
        $this->travelTo('2026-10-12 04:00:00');
        DailyClosing::create(['date' => '2026-10-10', 'closed_by' => $this->admin->id]);

        $this->open($this->manager, ['account_type' => 'loan', 'principal_amount' => '1000', 'opened_at' => '2026-10-10']);

        $this->assertSame(0, Account::count());
        $this->assertSame(0, Transaction::count());
    }

    public function test_loan_needs_a_positive_principal_and_a_valid_extra(): void
    {
        $this->open($this->manager, ['account_type' => 'loan'])->assertSessionHasErrors('principal_amount');
        $this->open($this->manager, ['account_type' => 'loan', 'principal_amount' => '0'])->assertSessionHasErrors('principal_amount');
        $this->open($this->manager, ['account_type' => 'loan', 'principal_amount' => 'abc'])->assertSessionHasErrors('principal_amount');
        $this->open($this->manager, ['account_type' => 'loan', 'principal_amount' => '1000', 'extra_amount' => 'x'])->assertSessionHasErrors('extra_amount');
        $this->open($this->manager, ['account_type' => 'loan', 'principal_amount' => '1000', 'payment_method' => 'gold'])->assertSessionHasErrors('payment_method');

        $this->assertSame(0, Account::count());
    }

    public function test_saving_takes_an_optional_agreed_amount(): void
    {
        $this->open($this->manager, ['account_type' => 'saving'])->assertSessionHasNoErrors();
        $this->open($this->manager, ['account_type' => 'saving', 'contract_amount' => '10,00,000'])->assertSessionHasNoErrors();

        $this->assertSame([null, 100_000_000], Account::query()->orderBy('id')->get()->map(fn (Account $account) => $account->contract_amount?->poisha)->all());
    }

    public function test_saving_agreed_amount_is_set_and_changed_through_adjustments(): void
    {
        $saving = Account::factory()->create();
        $adjust = fn (array $data) => $this->actingAs($this->manager)->post(route('admin.accounts.adjust', $saving), $data);

        $adjust(['contract_amount' => '1000000', 'reason' => 'Agreed at enrolment'])->assertSessionHasNoErrors();
        $adjust(['contract_amount' => '950000', 'reason' => 'Term shortened'])->assertSessionHasNoErrors();

        $adjustments = AccountAdjustment::query()->orderBy('id')->get();
        $this->assertNull($adjustments[0]->old_amount);
        $this->assertSame(100_000_000, $adjustments[0]->new_amount->poisha);
        $this->assertSame(100_000_000, $adjustments[1]->old_amount?->poisha);
        $this->assertSame(95_000_000, $saving->fresh()?->contract_amount?->poisha);
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
        $this->open($this->manager, ['account_type' => 'loan', 'principal_amount' => '1000'])->assertSessionHasNoErrors();
        $this->open($this->manager, ['account_type' => 'loan', 'principal_amount' => '2000'])->assertSessionHasNoErrors();

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

    public function test_adjustment_needs_reason_a_changed_amount_and_an_active_account(): void
    {
        $loan = Account::factory()->loan('10000')->create();
        $closed = Account::factory()->loan('10000')->closed()->create();
        $adjust = fn (Account $account, array $data) => $this->actingAs($this->manager)->post(route('admin.accounts.adjust', $account), $data);

        $adjust($loan, ['contract_amount' => '12000'])->assertSessionHasErrors('reason');
        $adjust($loan, ['contract_amount' => '10000', 'reason' => 'same'])->assertSessionHasErrors('contract_amount');
        $adjust($closed, ['contract_amount' => '12000', 'reason' => 'late'])->assertSessionHasErrors('contract_amount');

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

    public function test_saving_page_leads_with_balance_actions_and_recent_entries(): void
    {
        $saving = Account::factory()->for($this->party)->create(['contract_amount' => Money::fromTaka('1000')]);
        Transaction::factory()->of($saving, TransactionType::SavingDeposit, '300')->create(['description' => 'First deposit']);

        $this->actingAs($this->admin)->get(route('admin.accounts.show', $saving))
            ->assertOk()
            ->assertSeeInOrder([
                __('app.accounts.current_balance'), '৳৩০০.০০',
                __('app.accounts.actions.entry_saving'), __('app.accounts.actions.plan_saving'),
                __('app.accounts.actions.add_date'), __('app.accounts.actions.close'),
                __('app.accounts.recent'), 'First deposit',
            ])
            ->assertSee(__('app.accounts.remaining_to_agreed'))
            ->assertSee('৳৭০০.০০')
            ->assertSee(route('admin.accounts.statement', $saving), false)
            // The deposit form opens by default; an opening balance is no longer offered once entries exist.
            ->assertSee('id="section-entry-body" class="accordion-collapse collapse show"', false)
            ->assertDontSee('value="saving_opening_balance"', false)
            ->assertSee('value="saving_maturity_credit"', false);
    }

    public function test_a_form_error_reopens_its_own_section(): void
    {
        $saving = Account::factory()->for($this->party)->create();

        $this->actingAs($this->admin)
            ->from(route('admin.accounts.show', $saving))
            ->followingRedirects()
            ->put(route('admin.accounts.collection-plan.update', $saving), ['frequency' => 'weekly', 'installment_amount' => '0'])
            ->assertOk()
            ->assertSee('id="section-plan-body" class="accordion-collapse collapse show"', false)
            ->assertDontSee('id="section-entry-body" class="accordion-collapse collapse show"', false);
    }

    public function test_closed_account_page_offers_no_actions(): void
    {
        $saving = Account::factory()->for($this->party)->closed()->create();

        $this->actingAs($this->manager)->get(route('admin.accounts.show', $saving))
            ->assertOk()
            ->assertSee(__('app.accounts.closed_notice'))
            ->assertSee(__('app.accounts.no_entries'))
            ->assertDontSee('data-account-section', false)
            ->assertDontSee(route('admin.accounts.transactions.store', $saving), false);
    }

    public function test_party_page_shows_each_accounts_balance_from_the_ledger(): void
    {
        $saving = Account::factory()->for($this->party)->create();
        $loan = Account::factory()->loan('10000')->for($this->party)->create();
        Transaction::factory()->of($saving, TransactionType::SavingDeposit, '800')->create();
        Transaction::factory()->of($saving, TransactionType::SavingWithdrawal, '300')->create();
        Transaction::factory()->of($loan, TransactionType::LoanCollection, '2500')->create();

        $this->actingAs($this->manager)->get(route('admin.parties.show', $this->party))
            ->assertOk()
            ->assertSee(__('app.accounts.balance'))
            ->assertSee('৫০০.০০')
            ->assertSee(__('app.accounts.outstanding'))
            ->assertSee('৭,৫০০.০০')
            ->assertSee(route('admin.accounts.statement', $saving), false)
            ->assertSee(route('admin.accounts.statement', $loan), false)
            ->assertSee(route('admin.parties.statement', $this->party), false)
            ->assertSee(__('app.parties.progress.we_hold'))
            ->assertSee(__('app.parties.progress.owes_us'));
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
