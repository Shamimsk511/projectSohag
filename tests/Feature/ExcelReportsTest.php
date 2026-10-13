<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\OfficeHead;
use App\Models\Party;
use App\Models\PartyAssignment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountService;
use App\Services\ReportService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExcelReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
        $this->manager = User::factory()->withRole(Role::Manager)->create();
    }

    private function tx(Account $account, TransactionType $type, string $taka, string $date): Transaction
    {
        return Transaction::factory()->of($account, $type, $taka)->create(['transaction_date' => $date]);
    }

    private function loan(string $name, string $principal, string $extra, string $date): Account
    {
        return app(AccountService::class)->openLoan(
            Party::factory()->create(['name' => $name]),
            Money::fromTaka($principal),
            Money::fromTaka($extra),
            PaymentMethod::Cash,
            Carbon::parse($date),
            $this->manager,
        );
    }

    public function test_loan_register_lists_loans_given_in_the_period_with_agreed_extra(): void
    {
        $officer = User::factory()->withRole(Role::FieldOfficer)->create(['name' => 'Alice Officer']);
        $first = $this->loan('Rahim Member', '50000', '8250', '2026-10-03');
        $this->loan('Karim Member', '20000', '0', '2026-10-09');
        $this->loan('Outside Member', '99999', '1', '2026-09-30');
        PartyAssignment::factory()->create(['party_id' => $first->party_id, 'field_officer_id' => $officer->id]);
        $reversed = $this->loan('Reversed Member', '7000', '700', '2026-10-05');
        Transaction::factory()->reversing(Transaction::where('account_id', $reversed->id)->sole())->create(['transaction_date' => '2026-10-05']);

        $report = app(ReportService::class)->loanRegister(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

        $this->assertSame(2, $report['count']);
        $this->assertSame(Money::fromTaka('70000')->poisha, $report['principal']->poisha);
        $this->assertSame(Money::fromTaka('8250')->poisha, $report['extra']->poisha);
        $line = $report['rows'][0];
        $this->assertSame($first->id, $line['account']->id);
        $this->assertSame(Money::fromTaka('58250')->poisha, $line['contract']?->poisha);
        $this->assertSame(Money::fromTaka('8250')->poisha, $line['extra']?->poisha);
        $this->assertSame('Alice Officer', $line['officer']?->name);
        $this->assertNull($report['rows'][1]['officer']);
    }

    public function test_withdrawal_register_lists_effective_withdrawals_only(): void
    {
        $saving = Account::factory()->create();
        $this->tx($saving, TransactionType::SavingDeposit, '1000', '2026-10-01');
        $this->tx($saving, TransactionType::SavingWithdrawal, '300', '2026-10-04');
        $reversed = $this->tx($saving, TransactionType::SavingWithdrawal, '100', '2026-10-05');
        Transaction::factory()->reversing($reversed)->create(['transaction_date' => '2026-10-05']);
        $this->tx($saving, TransactionType::SavingWithdrawal, '50', '2026-09-30');

        $report = app(ReportService::class)->withdrawalRegister(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

        $this->assertCount(1, $report['rows']);
        $this->assertSame(Money::fromTaka('300')->poisha, $report['total']->poisha);
    }

    public function test_daily_cash_book_has_one_row_per_day_with_running_cash(): void
    {
        $loan = Account::factory()->loan('90000')->create();
        $saving = Account::factory()->create();
        // Before the period: +1000 in, 400 out = opening 600.
        $this->tx($saving, TransactionType::SavingDeposit, '1000', '2026-09-20');
        $this->tx($saving, TransactionType::SavingWithdrawal, '400', '2026-09-25');
        // In the period.
        $this->tx($loan, TransactionType::LoanCollection, '500', '2026-10-02');
        $this->tx($saving, TransactionType::SavingDeposit, '200', '2026-10-02');
        $this->tx($loan, TransactionType::LoanDisbursement, '1000', '2026-10-04');
        $this->tx($saving, TransactionType::SavingWithdrawal, '50', '2026-10-04');
        Transaction::factory()->office(TransactionType::OfficeIncome, '30')->create(['transaction_date' => '2026-10-04']);
        Transaction::factory()->office(TransactionType::OfficeExpense, '20')->create(['transaction_date' => '2026-10-04']);
        $reversed = $this->tx($saving, TransactionType::SavingDeposit, '999', '2026-10-03');
        Transaction::factory()->reversing($reversed)->create(['transaction_date' => '2026-10-03']);
        $this->tx($saving, TransactionType::SavingOpeningBalance, '5000', '2026-10-03'); // book entry: no cash

        $book = app(ReportService::class)->dailyCashBook(Carbon::parse('2026-10-02'), Carbon::parse('2026-10-04'));

        $this->assertSame(Money::fromTaka('600')->poisha, $book['opening']->poisha);
        $this->assertCount(3, $book['days']);
        [$d2, $d3, $d4] = $book['days'];
        $this->assertSame('2026-10-02', $d2['date']->toDateString());
        $this->assertSame(Money::fromTaka('500')->poisha, $d2['loan_collection']->poisha);
        $this->assertSame(Money::fromTaka('200')->poisha, $d2['saving_deposit']->poisha);
        $this->assertSame(Money::fromTaka('700')->poisha, $d2['net']->poisha);
        $this->assertSame(Money::fromTaka('1300')->poisha, $d2['running']->poisha);
        $this->assertSame(0, $d3['net']->poisha);
        $this->assertSame(Money::fromTaka('1300')->poisha, $d3['running']->poisha);
        $this->assertSame(Money::fromTaka('1000')->poisha, $d4['loan_disbursement']->poisha);
        $this->assertSame(Money::fromTaka('50')->poisha, $d4['saving_withdrawal']->poisha);
        $this->assertSame(Money::fromTaka('30')->poisha, $d4['office_income']->poisha);
        $this->assertSame(Money::fromTaka('20')->poisha, $d4['office_expense']->poisha);
        $this->assertSame(Money::fromTaka('-1040')->poisha, $d4['net']->poisha); // 30-20-1000-50
        $this->assertSame(Money::fromTaka('260')->poisha, $d4['running']->poisha);
        $this->assertSame(Money::fromTaka('260')->poisha, $book['closing']->poisha);
        $this->assertSame(Money::fromTaka('500')->poisha, $book['totals']['loan_collection']->poisha);
    }

    public function test_monthly_report_has_opening_and_closing_cash_loans_extra_and_office_heads(): void
    {
        $saving = Account::factory()->create();
        $this->tx($saving, TransactionType::SavingDeposit, '1000', '2026-09-15');
        $this->loan('Rahim Member', '400', '60', '2026-10-03');
        $this->tx($saving, TransactionType::SavingDeposit, '200', '2026-10-04');
        $fee = OfficeHead::factory()->create(['name' => 'Admission fee']);
        Transaction::factory()->office(TransactionType::OfficeIncome, '30')->create(['transaction_date' => '2026-10-05', 'office_head_id' => $fee->id]);
        Transaction::factory()->office(TransactionType::OfficeExpense, '10')->create(['transaction_date' => '2026-10-06']);

        $report = app(ReportService::class)->monthly(Carbon::parse('2026-10-01'));

        $this->assertSame(Money::fromTaka('1000')->poisha, $report['opening']->poisha);
        $this->assertSame(Money::fromTaka('230')->poisha, $report['money_in']->poisha); // 200 + 30
        $this->assertSame(Money::fromTaka('410')->poisha, $report['money_out']->poisha); // 400 + 10
        $this->assertSame(Money::fromTaka('820')->poisha, $report['closing']->poisha);
        $this->assertSame(1, $report['loans']['count']);
        $this->assertSame(Money::fromTaka('60')->poisha, $report['loans']['extra']->poisha);
        $this->assertSame(['Admission fee'], collect($report['heads'])->map(fn (array $line): ?string => $line['head']?->name)->filter()->values()->all());
    }

    public function test_pages_render_for_office_roles_and_not_for_field_officers(): void
    {
        $this->loan('Rahim Member', '400', '60', '2026-10-03');
        $saving = Account::factory()->create();
        $this->tx($saving, TransactionType::SavingWithdrawal, '50', '2026-10-04');
        $query = ['from' => '2026-10-01', 'to' => '2026-10-31'];

        $this->actingAs($this->manager)->get(route('admin.reports.loan-register', $query))->assertOk()
            ->assertSeeText('Rahim Member')->assertSeeText(Money::fromTaka('60')->format());
        $this->actingAs($this->manager)->get(route('admin.reports.withdrawal-register', $query))->assertOk()
            ->assertSeeText(Money::fromTaka('50')->format());
        $this->actingAs($this->manager)->get(route('admin.reports.daily-cash-book', $query))->assertOk()
            ->assertSeeText(__('app.reports.cash_book.title'));
        $this->actingAs($this->manager)->get(route('admin.reports.monthly', ['month' => '2026-10']))->assertOk()
            ->assertSeeText(__('app.reports.monthly.closing_cash'));

        $officer = User::factory()->withRole(Role::FieldOfficer)->create();
        $this->actingAs($officer)->get(route('admin.reports.loan-register'))->assertForbidden();
    }
}
