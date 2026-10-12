<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TransactionScreensTest extends TestCase
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
    private function postEntry(User $actor, Account $account, array $data): TestResponse
    {
        return $this->actingAs($actor)->post(route('admin.accounts.transactions.store', $account), $data + [
            'transaction_date' => $this->today,
            'payment_method' => 'cash',
        ]);
    }

    public function test_accountant_records_a_deposit_with_a_receipt(): void
    {
        $account = Account::factory()->create();

        $response = $this->postEntry($this->accountant, $account, [
            'type' => 'saving_deposit', 'amount' => '১,৫০০', 'payment_method' => 'bank', 'reference' => 'CHQ-7', 'description' => 'Monthly',
        ]);

        $transaction = Transaction::sole();
        $response->assertRedirect(route('admin.accounts.show', $account))
            ->assertSessionHas('status', __('app.transactions.recorded_with_receipt', ['receipt' => $transaction->receipt_no]));
        $this->assertSame(TransactionType::SavingDeposit, $transaction->type);
        $this->assertSame(150_000, $transaction->amount->poisha);
        $this->assertSame(PaymentMethod::Bank, $transaction->payment_method);
        $this->assertSame('CHQ-7', $transaction->reference);
        $this->assertSame('Monthly', $transaction->description);
        $this->assertSame($account->party_id, $transaction->party_id);
        $this->assertSame($this->accountant->id, $transaction->created_by);
        $this->assertNotNull($transaction->receipt_no);
    }

    public function test_disbursement_is_recorded_without_a_receipt(): void
    {
        $loan = Account::factory()->loan()->create();

        $this->postEntry($this->accountant, $loan, ['type' => 'loan_disbursement', 'amount' => '8000'])
            ->assertRedirect(route('admin.accounts.show', $loan))
            ->assertSessionHas('status', __('app.transactions.recorded'));

        $this->assertNull(Transaction::sole()->receipt_no);
    }

    #[TestWith(['loan_collection'])]
    #[TestWith(['loan_disbursement'])]
    #[TestWith(['office_income'])]
    public function test_only_the_account_types_entry_types_are_accepted(string $type): void
    {
        $account = Account::factory()->create();

        $this->postEntry($this->accountant, $account, ['type' => $type, 'amount' => '100'])->assertSessionHasErrors('type');

        $this->assertSame(0, Transaction::count());
    }

    public function test_invalid_input_is_rejected(): void
    {
        $account = Account::factory()->create();

        $this->postEntry($this->accountant, $account, [
            'type' => 'saving_deposit', 'amount' => '0', 'transaction_date' => '12/10/2026', 'payment_method' => 'cheque',
        ])->assertSessionHasErrors(['amount', 'transaction_date', 'payment_method']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_service_rules_come_back_as_field_errors(): void
    {
        $account = Account::factory()->create();
        Transaction::factory()->of($account, TransactionType::SavingDeposit, '500')->create();

        $this->postEntry($this->accountant, $account, ['type' => 'saving_withdrawal', 'amount' => '501'])
            ->assertSessionHasErrors(['amount' => __('app.transactions.exceeds_balance')]);

        $this->assertSame(1, Transaction::count());
    }

    public function test_roles_without_ledger_posting_are_refused(): void
    {
        $account = Account::factory()->create();

        $this->postEntry($this->manager, $account, ['type' => 'saving_deposit', 'amount' => '100'])->assertForbidden();
        $this->postEntry(User::factory()->withRole(Role::FieldOfficer)->create(), $account, ['type' => 'saving_deposit', 'amount' => '100'])
            ->assertForbidden();

        $this->assertSame(0, Transaction::count());
    }

    public function test_account_page_shows_the_entry_form_only_to_posters_of_an_active_account(): void
    {
        $account = Account::factory()->create();
        $closed = Account::factory()->closed()->create();
        $action = route('admin.accounts.transactions.store', $account);

        $this->actingAs($this->accountant)->get(route('admin.accounts.show', $account))->assertOk()->assertSee($action);
        $this->actingAs($this->manager)->get(route('admin.accounts.show', $account))->assertOk()->assertDontSee($action);
        $this->actingAs($this->accountant)->get(route('admin.accounts.show', $closed))->assertOk()
            ->assertDontSee(route('admin.accounts.transactions.store', $closed));
    }

    public function test_manager_reverses_with_a_reason(): void
    {
        $account = Account::factory()->create();
        $original = Transaction::factory()->of($account, TransactionType::SavingDeposit, '500')->create();

        $this->actingAs($this->manager)->post(route('admin.transactions.reverse', $original), ['reason' => 'Wrong amount'])
            ->assertRedirect(route('admin.accounts.statement', $account))
            ->assertSessionHas('status', __('app.transactions.reversed'));

        $reversal = Transaction::query()->where('reverses_transaction_id', $original->id)->sole();
        $this->assertSame(TransactionType::Reversal, $reversal->type);
        $this->assertSame('Wrong amount', $reversal->description);
        $this->assertSame($this->manager->id, $reversal->created_by);
    }

    public function test_reversal_needs_a_reason(): void
    {
        $original = Transaction::factory()->of(Account::factory()->create(), TransactionType::SavingDeposit, '500')->create();

        $this->actingAs($this->manager)->post(route('admin.transactions.reverse', $original), ['reason' => ' '])
            ->assertSessionHasErrors('reason');

        $this->assertSame(1, Transaction::count());
    }

    public function test_service_rejection_of_a_reversal_comes_back_as_an_error(): void
    {
        $original = Transaction::factory()->of(Account::factory()->create(), TransactionType::SavingDeposit, '500')->create();
        Transaction::factory()->reversing($original)->create();

        $this->actingAs($this->manager)->post(route('admin.transactions.reverse', $original), ['reason' => 'Again'])
            ->assertSessionHasErrors(['reason' => __('app.transactions.already_reversed')]);
    }

    public function test_accountant_cannot_reverse(): void
    {
        $original = Transaction::factory()->of(Account::factory()->create(), TransactionType::SavingDeposit, '500')->create();

        $this->actingAs($this->accountant)->post(route('admin.transactions.reverse', $original), ['reason' => 'Wrong'])
            ->assertForbidden();

        $this->assertSame(1, Transaction::count());
    }

    public function test_statement_offers_reversal_only_for_reversible_rows_to_reversers(): void
    {
        $account = Account::factory()->create();
        $open = Transaction::factory()->of($account, TransactionType::SavingDeposit, '500')->create();
        $reversed = Transaction::factory()->of($account, TransactionType::SavingDeposit, '300')->create();
        $reversal = Transaction::factory()->reversing($reversed)->create();
        $statement = route('admin.accounts.statement', $account);

        $this->actingAs($this->manager)->get($statement)->assertOk()
            ->assertSee(route('admin.transactions.reverse', $open))
            ->assertDontSee(route('admin.transactions.reverse', $reversed))
            ->assertDontSee(route('admin.transactions.reverse', $reversal));

        $this->actingAs($this->accountant)->get($statement)->assertOk()
            ->assertDontSee(route('admin.transactions.reverse', $open));
    }

    public function test_statement_of_a_closed_account_offers_no_reversal(): void
    {
        $account = Account::factory()->closed()->create();
        $row = Transaction::factory()->of($account, TransactionType::SavingDeposit, '500')->create();

        $this->actingAs($this->manager)->get(route('admin.accounts.statement', $account))->assertOk()
            ->assertDontSee(route('admin.transactions.reverse', $row));
    }
}
