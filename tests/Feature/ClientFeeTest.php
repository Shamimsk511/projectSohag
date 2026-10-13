<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\OfficeHead;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A fee taken from the member while opening an account or posting an entry: its own office income
 * row on the account, saved with the main entry or not at all.
 */
class ClientFeeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private OfficeHead $head;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->withRole(Role::Admin)->create();
        $this->head = OfficeHead::factory()->create();
    }

    public function test_loan_open_records_the_fee_as_office_income_with_a_receipt(): void
    {
        $party = Party::factory()->create();

        $this->actingAs($this->admin)->post(route('admin.parties.accounts.store', $party), [
            'account_type' => 'loan',
            'principal_amount' => '10000',
            'payment_method' => 'cash',
            'opened_at' => today(config('app.display_timezone'))->toDateString(),
            'fee_head_id' => $this->head->id,
            'fee_amount' => '200',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', fn (string $status): bool => str_contains($status, Money::fromTaka('9800')->format()));

        $account = Account::sole();
        $fee = Transaction::query()->where('type', TransactionType::OfficeIncome)->sole();
        $this->assertSame($account->id, $fee->account_id);
        $this->assertSame($this->head->id, $fee->office_head_id);
        $this->assertSame(20_000, $fee->amount->poisha);
        $this->assertNotNull($fee->receipt_no);
        $this->assertSame(1_000_000, app(LedgerService::class)->loanOutstanding($account)->poisha);
    }

    public function test_withdrawal_with_fee_records_both_rows_and_leaves_the_balance_to_the_withdrawal(): void
    {
        $account = Account::factory()->create();
        Transaction::factory()->of($account, TransactionType::SavingDeposit, '5000')->create();

        $this->actingAs($this->admin)->post(route('admin.accounts.transactions.store', $account), [
            'type' => 'saving_withdrawal',
            'amount' => '3000',
            'transaction_date' => today(config('app.display_timezone'))->toDateString(),
            'payment_method' => 'cash',
            'fee_head_id' => $this->head->id,
            'fee_amount' => '50',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Transaction::query()->where('type', TransactionType::SavingWithdrawal)->count());
        $this->assertSame(5_000, Transaction::query()->where('type', TransactionType::OfficeIncome)->sole()->amount->poisha);
        $this->assertSame(200_000, app(LedgerService::class)->savingBalance($account)->poisha);
    }

    public function test_a_rejected_fee_saves_nothing(): void
    {
        $account = Account::factory()->create();
        Transaction::factory()->of($account, TransactionType::SavingDeposit, '5000')->create();
        $expenseHead = OfficeHead::factory()->expense()->create();

        $this->actingAs($this->admin)->post(route('admin.accounts.transactions.store', $account), [
            'type' => 'saving_withdrawal',
            'amount' => '3000',
            'transaction_date' => today(config('app.display_timezone'))->toDateString(),
            'payment_method' => 'cash',
            'fee_head_id' => $expenseHead->id,
            'fee_amount' => '50',
        ])->assertSessionHasErrors();

        $this->assertSame(0, Transaction::query()->where('type', TransactionType::SavingWithdrawal)->count());
        $this->assertSame(0, Transaction::query()->where('type', TransactionType::OfficeIncome)->count());
    }

    public function test_staff_who_cannot_post_office_entries_cannot_send_a_fee(): void
    {
        $manager = User::factory()->withRole(Role::Manager)->create();
        $party = Party::factory()->create();

        $this->actingAs($manager)->post(route('admin.parties.accounts.store', $party), [
            'account_type' => 'saving',
            'opened_at' => today(config('app.display_timezone'))->toDateString(),
            'fee_head_id' => $this->head->id,
            'fee_amount' => '200',
        ])->assertSessionHasErrors(['fee_head_id', 'fee_amount']);

        $this->assertSame(0, Account::count());
    }

    public function test_fee_fields_show_on_the_open_form_only_for_office_posters(): void
    {
        $party = Party::factory()->create();
        $manager = User::factory()->withRole(Role::Manager)->create();

        $this->actingAs($this->admin)->get(route('admin.parties.accounts.create', $party))->assertSee('name="fee_amount"', false);
        $this->actingAs($manager)->get(route('admin.parties.accounts.create', $party))->assertDontSee('name="fee_amount"', false);
    }
}
