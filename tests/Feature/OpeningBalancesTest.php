<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\OpeningBalance;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashService;
use App\Services\LedgerService;
use App\Services\ReportService;
use App\Services\TransactionService;
use App\Support\Money;
use App\Support\TransactionData;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class OpeningBalancesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private Party $party;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo('2026-10-12 06:00:00');
        $this->admin = User::factory()->withRole(Role::Admin)->create();
        $this->manager = User::factory()->withRole(Role::Manager)->create();
        $this->party = Party::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function setCash(User $actor, array $data = []): TestResponse
    {
        return $this->actingAs($actor)->post(route('admin.opening-cash.store'), $data + ['amount' => '77,000', 'as_of' => '2026-10-01', 'note' => 'cash box']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function openExisting(User $actor, array $data = []): TestResponse
    {
        return $this->actingAs($actor)->post(route('admin.parties.accounts.store', $this->party), $data + [
            'account_type' => 'loan',
            'existing_loan' => '1',
            'principal_amount' => '50,000',
            'extra_amount' => '5,000',
            'received_amount' => '20,000',
            'opened_at' => '2026-03-01',
            'carried_as_of' => '2026-09-30',
        ]);
    }

    public function test_admin_sets_opening_cash_once_and_it_is_audited(): void
    {
        $this->setCash($this->admin)->assertRedirect(route('admin.opening-cash.index'))->assertSessionHasNoErrors();

        $balance = OpeningBalance::sole();
        $this->assertSame(7_700_000, $balance->amount->poisha);
        $this->assertSame('2026-10-01', $balance->as_of->toDateString());
        $this->assertSame($this->admin->id, $balance->set_by);
        $this->assertSame(1, AuditLog::query()->where('action', 'opening_cash.set')->count());

        $this->setCash($this->admin, ['amount' => '1'])->assertSessionHasErrors('as_of');
        $this->assertSame(1, OpeningBalance::count());
        $this->actingAs($this->admin)->get(route('admin.opening-cash.index'))->assertOk()->assertSee('৳');
    }

    public function test_only_admin_can_see_or_set_opening_cash(): void
    {
        $this->actingAs($this->manager)->get(route('admin.opening-cash.index'))->assertForbidden();
        $this->setCash($this->manager)->assertForbidden();
        $this->assertSame(0, OpeningBalance::count());
    }

    public function test_opening_cash_date_rules(): void
    {
        $this->setCash($this->admin, ['as_of' => '2026-10-13'])->assertSessionHasErrors('as_of');
        $this->setCash($this->admin, ['amount' => '0'])->assertSessionHasErrors('amount');

        $loan = Account::factory()->loan('10000')->create(['opened_at' => '2026-09-01']);
        app(TransactionService::class)->record(new TransactionData(
            type: TransactionType::LoanDisbursement,
            amount: Money::fromTaka('1000'),
            date: Carbon::parse('2026-09-10'),
            account: $loan,
        ), $this->admin);

        $this->setCash($this->admin, ['as_of' => '2026-09-11'])->assertSessionHasErrors('as_of');
        $this->setCash($this->admin, ['as_of' => '2026-09-10'])->assertSessionHasNoErrors();
    }

    public function test_opening_cash_is_immutable(): void
    {
        $this->setCash($this->admin);

        $this->expectException(LogicException::class);
        OpeningBalance::sole()->update(['note' => 'changed']);
    }

    public function test_opening_cash_feeds_office_cash_and_the_cash_book(): void
    {
        $this->setCash($this->admin, ['amount' => '1000', 'as_of' => '2026-10-02']);
        $saving = Account::factory()->create(['opened_at' => '2026-10-02']);
        app(TransactionService::class)->record(new TransactionData(
            type: TransactionType::SavingDeposit,
            amount: Money::fromTaka('200'),
            date: Carbon::parse('2026-10-03'),
            account: $saving,
        ), $this->admin);

        $ledger = app(LedgerService::class);
        $this->assertSame(0, $ledger->officeCashBalance(Carbon::parse('2026-10-01'))->poisha);
        $this->assertSame(100_000, $ledger->officeCashBalance(Carbon::parse('2026-10-02'))->poisha);
        $this->assertSame(120_000, $ledger->officeCashBalance(Carbon::parse('2026-10-03'))->poisha);

        $book = app(ReportService::class)->dailyCashBook(Carbon::parse('2026-10-02'), Carbon::parse('2026-10-03'));
        $this->assertSame(100_000, $book['opening']->poisha);
        $this->assertSame(120_000, $book['closing']->poisha);
    }

    public function test_admin_opens_an_existing_loan_with_book_entries_only(): void
    {
        $this->openExisting($this->admin)->assertSessionHasNoErrors();

        $account = Account::sole();
        $this->assertSame(5_500_000, $account->contract_amount?->poisha);
        $this->assertSame('2026-03-01', $account->opened_at->toDateString());

        $rows = Transaction::query()->orderBy('id')->get();
        $this->assertSame([TransactionType::LoanOpeningDisbursement, TransactionType::LoanOpeningReceived], $rows->pluck('type')->all());
        $this->assertSame(['2026-03-01', '2026-09-30'], $rows->map(fn (Transaction $row): string => $row->transaction_date->toDateString())->all());
        $this->assertNull($rows[0]->payment_method);
        $this->assertNull($rows[1]->receipt_no);
        $this->assertSame(2, AuditLog::query()->where('action', 'transaction.recorded')->count());

        $ledger = app(LedgerService::class);
        $this->assertSame(5_000_000, $ledger->loanDisbursed($account)->poisha);
        $this->assertSame(500_000, $ledger->agreedExtra($account)?->poisha);
        $this->assertSame(2_000_000, $ledger->loanReceived($account)->poisha);
        $this->assertSame(3_500_000, $ledger->loanOutstanding($account)->poisha);
        $this->assertSame(3_500_000, $ledger->totalLoanOutstanding()->poisha);
        $this->assertSame(['loans' => 0], ['loans' => $ledger->loansIssued(Carbon::parse('2026-01-01'), Carbon::parse('2026-10-12'))['count']]);
    }

    public function test_carried_in_rows_move_no_cash_and_no_collection_figures(): void
    {
        $this->openExisting($this->admin)->assertSessionHasNoErrors();

        $ledger = app(LedgerService::class);
        $this->assertSame(0, $ledger->officeCashBalance(null)->poisha);
        $this->assertSame(0, $ledger->receivedOn(Carbon::parse('2026-09-30'))->poisha);
        $book = app(ReportService::class)->dailyCashBook(Carbon::parse('2026-09-29'), Carbon::parse('2026-10-01'));
        $this->assertSame(0, $book['closing']->poisha);
    }

    public function test_a_collection_after_carry_in_reduces_outstanding_and_the_statement_shows_both(): void
    {
        $this->openExisting($this->admin)->assertSessionHasNoErrors();
        $account = Account::sole();

        app(TransactionService::class)->record(new TransactionData(
            type: TransactionType::LoanCollection,
            amount: Money::fromTaka('1000'),
            date: Carbon::parse('2026-10-05'),
            account: $account,
            paymentMethod: PaymentMethod::Cash,
        ), $this->admin);

        $ledger = app(LedgerService::class);
        $this->assertSame(3_400_000, $ledger->loanOutstanding($account)->poisha);
        $statement = $ledger->statement($account);
        $this->assertCount(3, $statement->lines);
        $this->assertSame(2_100_000, $statement->closing->poisha);
        $this->actingAs($this->admin)->get(route('admin.accounts.statement', $account))->assertOk();
    }

    public function test_received_is_optional_and_may_not_exceed_the_contract(): void
    {
        $this->openExisting($this->admin, ['received_amount' => null])->assertSessionHasNoErrors();
        $this->assertSame(1, Transaction::count());

        $this->openExisting($this->admin, ['received_amount' => '55,001'])->assertSessionHasErrors('amount');
        $this->assertSame(1, Account::count());
        $this->assertSame(1, Transaction::count());
    }

    public function test_carried_as_of_must_not_precede_the_loan_date_or_be_in_the_future(): void
    {
        $this->openExisting($this->admin, ['carried_as_of' => '2026-02-01'])->assertSessionHasErrors('carried_as_of');
        $this->openExisting($this->admin, ['carried_as_of' => '2026-10-13'])->assertSessionHasErrors('carried_as_of');
        $this->openExisting($this->admin, ['carried_as_of' => null])->assertSessionHasErrors('carried_as_of');
        $this->assertSame(0, Account::count());
    }

    public function test_a_manager_cannot_carry_in_a_loan(): void
    {
        $this->openExisting($this->manager)->assertSessionHasErrors('existing_loan');

        $this->assertSame(0, Account::count());
        $this->assertSame(0, Transaction::count());
    }

    public function test_opening_entries_cannot_be_posted_later_from_the_entry_form(): void
    {
        $loan = Account::factory()->loan('10000')->create();

        $this->actingAs($this->admin)->post(route('admin.accounts.transactions.store', $loan), [
            'type' => 'loan_opening_received', 'amount' => '100', 'transaction_date' => '2026-10-12', 'payment_method' => 'cash',
        ])->assertSessionHasErrors('type');
        $this->assertSame(0, Transaction::count());
    }

    public function test_service_enforces_order_of_opening_rows(): void
    {
        $loan = Account::factory()->loan('10000')->create(['opened_at' => '2026-01-01']);
        $service = app(TransactionService::class);
        $make = fn (TransactionType $type, string $amount): Transaction => $service->record(new TransactionData(
            type: $type, amount: Money::fromTaka($amount), date: Carbon::parse('2026-09-01'), account: $loan,
        ), $this->admin);

        try {
            $make(TransactionType::LoanOpeningReceived, '10');
            $this->fail('received before disbursement must be rejected');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('type', $exception->errors());
        }

        $make(TransactionType::LoanOpeningDisbursement, '1000');
        $this->expectException(ValidationException::class);
        $make(TransactionType::LoanOpeningDisbursement, '1000');
    }

    public function test_automatic_closing_ignores_the_long_past_date_of_a_carried_in_loan(): void
    {
        $this->openExisting($this->admin, ['opened_at' => '2024-01-01'])->assertSessionHasNoErrors();

        $this->assertSame(0, app(CashService::class)->closeDueDays() > 1 ? 1 : 0);
    }
}
