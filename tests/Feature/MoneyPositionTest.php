<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\ScheduleStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\CashHandover;
use App\Models\CollectionSchedule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReportService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MoneyPositionTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
        $this->officer = User::factory()->withRole(Role::FieldOfficer)->create();
    }

    public function test_position_adds_up_cash_what_members_owe_and_what_we_owe(): void
    {
        $this->seedLedger();

        $position = app(ReportService::class)->position(Carbon::parse('2026-10-12'));

        $this->assertMoney('11450', $position['cash']['office']); // in 1500 + 19400, out 9450
        $this->assertMoney('500', $position['cash']['officers']); // 2000 - 1500 handed over
        $this->assertMoney('11950', $position['cash']['total']);

        $this->assertMoney('9000', $position['owed_to_us']['loans']); // paid-off loan left out
        $this->assertMoney('500', $position['owed_to_us']['overdue']);
        $this->assertSame(1, $position['owed_to_us']['count']);

        $this->assertMoney('1700', $position['we_owe']['savings']);
        $this->assertMoney('14000', $position['we_owe']['fdr']); // 12000 - 1000 agreed, 3000 deposited with no agreed payback
        $this->assertMoney('15700', $position['we_owe']['total']);

        $this->assertSame('2026-10-01', $position['month']['from']->toDateString());
        $this->assertMoney('13000', $position['month']['received']); // September rows and the reversed deposit left out
        $this->assertMoney('1300', $position['month']['paid_out']);
        $this->assertMoney('400', $position['month']['office_income']);
        $this->assertMoney('150', $position['month']['office_expense']);
    }

    public function test_page_shows_the_position_with_links_to_details(): void
    {
        $this->seedLedger();

        foreach ([Role::Admin, Role::Manager, Role::Accountant] as $role) {
            $this->actingAs(User::factory()->withRole($role)->create())
                ->get(route('admin.reports.position'))
                ->assertOk()
                ->assertSee(__('app.reports.position.title'))
                ->assertSee('৳১১,৯৫০.০০')
                ->assertSee('৳১৫,৭০০.০০')
                ->assertSee(route('admin.reports.outstanding'), false)
                ->assertSee(route('admin.reports.cash-reconciliation'), false);
        }

        $this->actingAs($this->officer)->get(route('admin.reports.position'))->assertForbidden();
    }

    public function test_dashboard_starts_with_a_compact_position_card(): void
    {
        $this->seedLedger();

        $this->actingAs(User::factory()->withRole(Role::Manager)->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.reports.position'), false)
            ->assertSee('৳১৫,৭০০.০০');
    }

    private function seedLedger(): void
    {
        $loan = Account::factory()->loan('10000')->create();
        $this->entry($loan, TransactionType::LoanDisbursement, '8000', '2026-09-20');
        $this->entry($loan, TransactionType::LoanCollection, '1000', '2026-10-05');
        CollectionSchedule::factory()->create([
            'account_id' => $loan->id,
            'party_id' => $loan->party_id,
            'field_officer_id' => $this->officer->id,
            'expected_amount' => Money::fromTaka('500'),
            'due_date' => '2026-10-10',
            'status' => ScheduleStatus::Missed,
        ]);

        $paidOff = Account::factory()->loan('5000')->create();
        $this->entry($paidOff, TransactionType::LoanCollection, '5000', '2026-09-25');

        $saving = Account::factory()->create();
        $this->entry($saving, TransactionType::SavingDeposit, '2000', '2026-10-11', $this->officer);
        $reversed = $this->entry($saving, TransactionType::SavingDeposit, '999', '2026-10-11', $this->officer);
        Transaction::factory()->reversing($reversed)->create(['transaction_date' => '2026-10-11']);
        $this->entry($saving, TransactionType::SavingWithdrawal, '300', '2026-10-12');
        CashHandover::factory()->create([
            'field_officer_id' => $this->officer->id,
            'amount' => Money::fromTaka('1500'),
            'handover_date' => '2026-10-12',
        ]);

        $fdr = Account::factory()->fdr('12000')->create();
        $this->entry($fdr, TransactionType::InvestmentDeposit, '10000', '2026-10-01');
        $this->entry($fdr, TransactionType::InvestmentReturn, '1000', '2026-10-12');
        $openFdr = Account::factory()->fdr()->create();
        $this->entry($openFdr, TransactionType::InvestmentDeposit, '3000', '2026-09-01');

        Transaction::factory()->office(TransactionType::OfficeIncome, '400')->create(['transaction_date' => '2026-10-03', 'payment_method' => 'cash']);
        Transaction::factory()->office(TransactionType::OfficeExpense, '150')->create(['transaction_date' => '2026-10-04', 'payment_method' => 'cash']);
    }

    private function entry(Account $account, TransactionType $type, string $taka, string $date, ?User $officer = null): Transaction
    {
        return Transaction::factory()->of($account, $type, $taka)->create([
            'transaction_date' => $date,
            'payment_method' => 'cash',
            'field_officer_id' => $officer?->id,
        ]);
    }

    private function assertMoney(string $taka, Money $actual): void
    {
        $this->assertSame(Money::fromTaka($taka)->poisha, $actual->poisha);
    }
}
