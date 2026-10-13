<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\ScheduleStatus;
use App\Enums\TransactionType;
use App\Exceptions\LedgerRuleViolation;
use App\Models\Account;
use App\Models\CollectionSchedule;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountService;
use App\Services\LedgerService;
use App\Services\ReportService;
use App\Services\TransactionService;
use App\Support\Money;
use App\Support\TransactionData;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FdrAccountTest extends TestCase
{
    use RefreshDatabase;

    private TransactionService $service;

    private LedgerService $ledger;

    private User $accountant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->service = app(TransactionService::class);
        $this->ledger = app(LedgerService::class);
        $this->accountant = User::factory()->withRole(Role::Accountant)->create();
        $this->admin = User::factory()->withRole(Role::Admin)->create();
    }

    public function test_fdr_type_wiring(): void
    {
        $this->assertSame('FD', AccountType::Fdr->prefix());
        $this->assertSame([TransactionType::InvestmentDeposit, TransactionType::InvestmentReturn], AccountType::Fdr->entryTypes());
        $this->assertSame(TransactionType::InvestmentDeposit, AccountType::Fdr->collectionType());
        $this->assertSame(
            [TransactionType::LoanCollection, TransactionType::SavingDeposit, TransactionType::InvestmentDeposit],
            TransactionType::collectionTypes(),
        );
        $this->assertTrue(TransactionType::InvestmentDeposit->isRecordable());
        $this->assertTrue(TransactionType::InvestmentDeposit->issuesReceipt());
        $this->assertFalse(TransactionType::InvestmentReturn->issuesReceipt());
        $this->assertSame(AccountType::Fdr, TransactionType::InvestmentReturn->accountType());
        $this->assertTrue(Account::factory()->fdr('1200')->create()->isFdr());
    }

    public function test_fdr_figures_and_statement_come_from_the_ledger(): void
    {
        $fdr = Account::factory()->fdr('1200')->create(['opened_at' => today(config('app.display_timezone'))->subDays(5)]);
        $this->record(TransactionType::InvestmentDeposit, '1000', $fdr);
        $this->record(TransactionType::InvestmentDeposit, '500', $fdr);
        Transaction::factory()->create([
            'account_id' => $fdr->id, 'party_id' => $fdr->party_id,
            'type' => TransactionType::InvestmentReturn, 'amount' => Money::fromTaka('300'),
        ]);

        $this->assertSame(150000, $this->ledger->fdrDeposited($fdr)->poisha);
        $this->assertSame(30000, $this->ledger->fdrReturned($fdr)->poisha);
        $this->assertSame(120000 - 30000, $this->ledger->fdrRemaining($fdr)->poisha);
        $this->assertSame(120000, $this->ledger->fdrHeld($fdr)->poisha);
        $this->assertSame($this->ledger->fdrRemaining($fdr)->poisha, $this->ledger->remainingBalance($fdr)->poisha);
        $this->assertSame($this->ledger->fdrHeld($fdr)->poisha, $this->ledger->statement($fdr)->closing->poisha);
        $this->assertSame(120000, $this->ledger->totalFdrHeld()->poisha);
        $this->assertSame(0, $this->ledger->totalSavingBalance()->poisha);
    }

    public function test_without_an_agreed_payback_remaining_is_what_was_deposited(): void
    {
        $fdr = Account::factory()->fdr()->create();
        $this->record(TransactionType::InvestmentDeposit, '700', $fdr);

        $this->assertSame(70000, $this->ledger->fdrRemaining($fdr)->poisha);
    }

    public function test_fdr_deposits_count_as_cash_collected_by_the_officer(): void
    {
        $officer = User::factory()->withRole(Role::FieldOfficer)->create();
        $fdr = Account::factory()->fdr()->create();
        $this->record(TransactionType::InvestmentDeposit, '400', $fdr, ['fieldOfficer' => $officer, 'paymentMethod' => PaymentMethod::Cash]);

        $this->assertSame(40000, $this->ledger->cashInHand([$officer->id])[$officer->id]->poisha);
        $this->assertSame(40000, $this->ledger->receivedOn(today(config('app.display_timezone')))->poisha);
    }

    public function test_deposit_posts_with_a_receipt_and_needs_no_cap(): void
    {
        $fdr = Account::factory()->fdr('1200')->create();

        $deposit = $this->record(TransactionType::InvestmentDeposit, '5000', $fdr);

        $this->assertNotNull($deposit->receipt_no);
        $this->assertSame($fdr->party_id, $deposit->party_id);
        $this->assertSame(PaymentMethod::Cash, $deposit->payment_method);
    }

    public function test_return_needs_an_agreed_payback_and_stays_within_it(): void
    {
        $noContract = Account::factory()->fdr()->create();
        $this->record(TransactionType::InvestmentDeposit, '1000', $noContract);
        $this->assertRejected(fn () => $this->record(TransactionType::InvestmentReturn, '100', $noContract), 'no_agreed_payback');

        $fdr = Account::factory()->fdr('1200')->create();
        $this->record(TransactionType::InvestmentDeposit, '300', $fdr);

        $early = $this->record(TransactionType::InvestmentReturn, '1000', $fdr);
        $this->assertNull($early->receipt_no);
        $this->assertSame(PaymentMethod::Cash, $early->payment_method);
        $this->assertRejected(fn () => $this->record(TransactionType::InvestmentReturn, '200.01', $fdr), 'exceeds_agreed_payback');
        $this->record(TransactionType::InvestmentReturn, '200', $fdr);
        $this->assertSame(0, $this->ledger->fdrRemaining($fdr)->poisha);
    }

    public function test_reversing_a_return_frees_room_and_a_deposit_can_be_reversed_freely(): void
    {
        $fdr = Account::factory()->fdr('1200')->create();
        $deposit = $this->record(TransactionType::InvestmentDeposit, '1000', $fdr);
        $return = $this->record(TransactionType::InvestmentReturn, '1200', $fdr);

        $this->service->reverse($deposit, 'wrong member', $this->admin);
        $this->assertSame(-120000, $this->ledger->fdrHeld($fdr)->poisha);

        $this->service->reverse($return, 'paid twice', $this->admin);
        $this->assertSame(120000, $this->ledger->fdrRemaining($fdr)->poisha);
        $this->record(TransactionType::InvestmentReturn, '1200', $fdr);
    }

    public function test_saving_types_are_rejected_on_an_fdr_account_and_vice_versa(): void
    {
        $fdr = Account::factory()->fdr('1200')->create();
        $saving = Account::factory()->create();

        $this->assertRejected(fn () => $this->record(TransactionType::SavingDeposit, '100', $fdr), 'account_mismatch');
        $this->assertRejected(fn () => $this->record(TransactionType::InvestmentDeposit, '100', $saving), 'account_mismatch');
    }

    public function test_scheduled_fdr_installment_settles_the_schedule_row(): void
    {
        $officer = User::factory()->withRole(Role::FieldOfficer)->create();
        $fdr = Account::factory()->fdr()->create();
        $schedule = CollectionSchedule::factory()->create([
            'account_id' => $fdr->id, 'field_officer_id' => $officer->id, 'expected_amount' => Money::fromTaka('100'),
        ]);

        $this->record(TransactionType::InvestmentDeposit, '60', $fdr, ['schedule' => $schedule, 'fieldOfficer' => $officer], $officer);
        $this->assertSame(ScheduleStatus::PartiallyPaid, $schedule->refresh()->status);

        $this->record(TransactionType::InvestmentDeposit, '40', $fdr, ['schedule' => $schedule, 'fieldOfficer' => $officer], $officer);
        $this->assertSame(ScheduleStatus::Paid, $schedule->refresh()->status);
    }

    public function test_opens_an_fdr_with_an_fd_number_and_optional_agreed_payback(): void
    {
        $party = Party::factory()->create();
        $accounts = app(AccountService::class);

        $first = $accounts->openFdr($party, Money::fromTaka('1200'), today(config('app.display_timezone')), $this->admin);
        $second = $accounts->openFdr($party, null, today(config('app.display_timezone')), $this->admin);

        $this->assertSame('FD-000001', $first->account_number);
        $this->assertSame('FD-000002', $second->account_number);
        $this->assertSame(AccountType::Fdr, $first->account_type);
        $this->assertSame(120000, $first->contract_amount->poisha);
        $this->assertNull($second->contract_amount);
    }

    public function test_agreed_payback_is_set_later_through_an_adjustment_with_a_reason(): void
    {
        $fdr = Account::factory()->fdr()->create();
        $accounts = app(AccountService::class);

        $adjustment = $accounts->adjustContractAmount($fdr, Money::fromTaka('1500'), 'agreed with member', $this->admin);

        $this->assertNull($adjustment->old_amount);
        $this->assertSame(150000, $fdr->refresh()->contract_amount->poisha);
    }

    public function test_close_needs_remaining_payback_zero_or_an_admin_override(): void
    {
        $manager = User::factory()->withRole(Role::Manager)->create();
        $accounts = app(AccountService::class);
        $fdr = Account::factory()->fdr('1200')->create();
        $this->record(TransactionType::InvestmentDeposit, '1000', $fdr);

        try {
            $accounts->close($fdr, null, $manager);
            $this->fail('Expected rejection.');
        } catch (ValidationException) {
            $this->assertTrue($fdr->refresh()->isActive());
        }

        $this->record(TransactionType::InvestmentReturn, '1200', $fdr);
        $this->assertFalse($accounts->close($fdr, null, $manager)->isActive());
    }

    public function test_open_form_posts_an_fdr_account(): void
    {
        $party = Party::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.parties.accounts.store', $party), [
                'account_type' => 'fdr',
                'contract_amount' => '1,200',
                'opened_at' => today(config('app.display_timezone'))->toDateString(),
            ])
            ->assertRedirect();

        $account = Account::query()->where('party_id', $party->id)->firstOrFail();
        $this->assertSame(AccountType::Fdr, $account->account_type);
        $this->assertSame(120000, $account->contract_amount->poisha);
    }

    public function test_account_page_shows_fdr_figures_and_the_entry_form(): void
    {
        $this->withoutVite();
        app()->setLocale('en');
        $fdr = Account::factory()->fdr('1200')->create();
        $this->record(TransactionType::InvestmentDeposit, '1000', $fdr);

        $this->actingAs($this->admin)->get(route('admin.accounts.show', $fdr))
            ->assertOk()
            ->assertSee('Deposited')
            ->assertSee('Agreed payback')
            ->assertSee('Paid back')
            ->assertSee('FDR payback')
            ->assertDontSee('app.accounts.')
            ->assertDontSee('app.transactions.');
    }

    public function test_payback_option_needs_an_agreed_payback(): void
    {
        $this->withoutVite();
        app()->setLocale('en');
        $without = Account::factory()->fdr()->create();
        $with = Account::factory()->fdr('1200')->create();

        $this->actingAs($this->admin)->get(route('admin.accounts.show', $without))
            ->assertOk()->assertDontSee('FDR payback')->assertDontSee('app.');
        $this->actingAs($this->admin)->get(route('admin.accounts.show', $with))
            ->assertOk()->assertSee('FDR payback');
    }

    public function test_customer_ledger_shows_fdr_agreed_and_remaining_payback(): void
    {
        $this->withoutVite();
        $fdr = Account::factory()->fdr('1200')->create();
        $this->record(TransactionType::InvestmentDeposit, '1000', $fdr);
        $this->record(TransactionType::InvestmentReturn, '300', $fdr);

        $this->actingAs($this->admin)->get(route('admin.parties.statement', $fdr->party))
            ->assertOk()
            ->assertSee(Money::fromTaka('1200')->format())
            ->assertSee(Money::fromTaka('900')->format());
    }

    public function test_fdr_payback_counts_in_what_we_hold_for_the_party_not_in_what_they_owe(): void
    {
        $this->withoutVite();
        $fdr = Account::factory()->fdr('1200')->create();
        $this->record(TransactionType::InvestmentDeposit, '1000', $fdr);

        $this->actingAs($this->admin)->get(route('admin.parties.show', $fdr->party))
            ->assertOk()
            ->assertViewHas('totals', fn (array $totals): bool => $totals['owes']->isZero() && $totals['holds']->poisha === Money::fromTaka('1200')->poisha);
    }

    public function test_statement_page_for_fdr_uses_neutral_labels(): void
    {
        $this->withoutVite();
        app()->setLocale('en');
        $fdr = Account::factory()->fdr('1200')->create();
        $this->record(TransactionType::InvestmentDeposit, '1000', $fdr);

        $this->actingAs($this->admin)->get(route('admin.accounts.statement', $fdr))
            ->assertOk()
            ->assertSee('Paid back')
            ->assertDontSee('app.accounts.')
            ->assertDontSee('app.transactions.');
    }

    public function test_cash_book_and_monthly_include_fdr_flows(): void
    {
        $fdr = Account::factory()->fdr('1200')->create();
        $this->record(TransactionType::InvestmentDeposit, '1000', $fdr);
        $this->record(TransactionType::InvestmentReturn, '300', $fdr);
        $day = today(config('app.display_timezone'));
        $reports = app(ReportService::class);

        $book = $reports->dailyCashBook($day, $day);
        $this->assertSame(100000, $book['totals']['investment_deposit']->poisha);
        $this->assertSame(30000, $book['totals']['investment_return']->poisha);
        $this->assertSame(70000, $book['closing']->poisha);

        $month = $reports->monthly($day);
        $this->assertSame(100000, $month['received']->poisha);
        $this->assertSame(30000, $month['paid_out']->poisha);
        $this->assertSame(70000, $month['closing']->poisha);
    }

    public function test_cash_book_opening_carries_earlier_fdr_flows(): void
    {
        $day = today(config('app.display_timezone'));
        $fdr = Account::factory()->fdr('1200')->create(['opened_at' => $day->copy()->subDays(5)]);
        $this->record(TransactionType::InvestmentDeposit, '1000', $fdr, ['date' => $day->copy()->subDays(3)]);
        $this->record(TransactionType::InvestmentReturn, '300', $fdr, ['date' => $day->copy()->subDays(2)]);

        $book = app(ReportService::class)->dailyCashBook($day, $day);

        $this->assertSame(70000, $book['opening']->poisha);
        $this->assertSame(70000, $book['closing']->poisha);
    }

    public function test_dashboard_shows_total_fdr_held(): void
    {
        $fdr = Account::factory()->fdr('1200')->create();
        $this->record(TransactionType::InvestmentDeposit, '1000', $fdr);

        $figures = app(ReportService::class)->dashboard(today(config('app.display_timezone')));

        $this->assertSame(100000, $figures['fdr_held']->poisha);
        $this->assertSame(0, $figures['saving_balance']->poisha);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(TransactionType $type, string $taka, Account $account, array $extra = [], ?User $actor = null): Transaction
    {
        return $this->service->record(new TransactionData(...[
            'type' => $type,
            'amount' => Money::fromTaka($taka),
            'date' => today(config('app.display_timezone')),
            'account' => $account,
            ...$extra,
        ]), $actor ?? $this->accountant);
    }

    /**
     * @param  callable(): mixed  $write
     */
    private function assertRejected(callable $write, ?string $rule = null): void
    {
        $before = Transaction::query()->count();

        try {
            $write();
            $this->fail('Expected the write to be rejected.');
        } catch (ValidationException $e) {
            $this->assertSame($before, Transaction::query()->count());
            if ($rule !== null) {
                $this->assertInstanceOf(LedgerRuleViolation::class, $e);
                $this->assertSame($rule, $e->rule);
            }
        }
    }
}
