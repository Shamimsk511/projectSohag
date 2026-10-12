<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\ScheduleStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\CollectionSchedule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReportService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
    }

    public function test_month_totals_schedule_figures_and_daily_series_come_from_the_ledger(): void
    {
        $loan = Account::factory()->loan('50000')->create();
        $saving = Account::factory()->create();

        $row = CollectionSchedule::factory()->create([
            'account_id' => $loan->id, 'party_id' => $loan->party_id, 'due_date' => '2026-10-05',
            'expected_amount' => Money::fromTaka('500'), 'status' => ScheduleStatus::Paid,
        ]);
        CollectionSchedule::factory()->create([
            'account_id' => $saving->id, 'party_id' => $saving->party_id, 'due_date' => '2026-10-31',
            'expected_amount' => Money::fromTaka('500'), 'status' => ScheduleStatus::Pending,
        ]);
        CollectionSchedule::factory()->create(['due_date' => '2026-09-30', 'expected_amount' => Money::fromTaka('900')]); // other month

        $this->tx($loan, TransactionType::LoanCollection, '600', '2026-10-05', $row->id);
        $this->tx($saving, TransactionType::SavingDeposit, '200', '2026-10-05');
        $this->tx($saving, TransactionType::SavingDeposit, '100', '2026-10-31');
        $this->tx($loan, TransactionType::LoanCollection, '777', '2026-09-30'); // other month
        $this->tx($loan, TransactionType::LoanDisbursement, '3000', '2026-10-02');
        $this->tx($saving, TransactionType::SavingWithdrawal, '50', '2026-10-09');
        $reversed = $this->tx($saving, TransactionType::SavingDeposit, '999', '2026-10-06');
        Transaction::factory()->reversing($reversed)->create(['transaction_date' => '2026-10-06']);
        Transaction::factory()->office(TransactionType::OfficeIncome, '400')->create(['transaction_date' => '2026-10-08']);
        Transaction::factory()->office(TransactionType::OfficeExpense, '150')->create(['transaction_date' => '2026-10-10']);

        $report = app(ReportService::class)->monthly(Carbon::parse('2026-10-01'));

        $this->assertSame(Money::fromTaka('900')->poisha, $report['received']->poisha); // 600+200+100
        $this->assertSame(Money::fromTaka('3050')->poisha, $report['paid_out']->poisha);
        $this->assertSame(Money::fromTaka('250')->poisha, $report['office_net']->poisha);
        $this->assertSame(Money::fromTaka('1000')->poisha, $report['expected']->poisha);
        $this->assertSame(Money::fromTaka('600')->poisha, $report['collected']->poisha);
        $this->assertSame(50, $report['rate']); // (500 capped + 0) / 1000
        $this->assertCount(31, $report['daily']);
        $this->assertSame(Money::fromTaka('800')->poisha, $report['daily']['2026-10-05']->poisha);
        $this->assertSame(Money::fromTaka('100')->poisha, $report['daily']['2026-10-31']->poisha);
        $this->assertTrue($report['daily']['2026-10-06']->isZero()); // reversed deposit drops out
        $this->assertArrayNotHasKey('reversal', $report['totals']);
    }

    public function test_page_defaults_to_this_month_and_validates_input(): void
    {
        $account = Account::factory()->create();
        $this->tx($account, TransactionType::SavingDeposit, '321', '2026-10-03');
        $this->tx($account, TransactionType::SavingDeposit, '654', '2026-09-03');

        $this->actingAs(User::factory()->withRole(Role::Accountant)->create())
            ->get(route('admin.reports.monthly'))
            ->assertOk()->assertSee('৩২১.০০')->assertDontSee('৬৫৪.০০')->assertSee('daily-chart', false);
        $this->get(route('admin.reports.monthly', ['month' => '2026-09']))
            ->assertOk()->assertSee('৬৫৪.০০');
        $this->get(route('admin.reports.monthly', ['month' => 'soon']))->assertSessionHasErrors('month');
    }

    private function tx(Account $account, TransactionType $type, string $taka, string $date, ?int $scheduleId = null): Transaction
    {
        return Transaction::factory()->of($account, $type, $taka)->create([
            'transaction_date' => $date, 'schedule_id' => $scheduleId, 'payment_method' => 'cash',
        ]);
    }
}
