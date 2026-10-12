<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\CashHandover;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReportService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CashReconciliationReportTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
        $this->alice = User::factory()->withRole(Role::FieldOfficer)->create(['name' => 'Alice Officer']);
    }

    public function test_officer_and_office_cash_rebuild_from_the_ledger(): void
    {
        $loan = Account::factory()->loan('50000')->create();
        $saving = Account::factory()->create();

        // Before the period: Alice collected 1000 cash, handed over 400.
        $this->row($loan, TransactionType::LoanCollection, '1000', '2026-09-20', $this->alice);
        $this->handover('400', '2026-09-25');
        // In the period.
        $this->row($loan, TransactionType::LoanCollection, '600', '2026-10-03', $this->alice);
        $this->row($saving, TransactionType::SavingDeposit, '200', '2026-10-04', $this->alice, 'bank'); // not cash
        $reversed = $this->row($loan, TransactionType::LoanCollection, '999', '2026-10-05', $this->alice);
        Transaction::factory()->reversing($reversed)->create(['transaction_date' => '2026-10-05']);
        $this->handover('500', '2026-10-06');
        // Office-side cash.
        $this->row($saving, TransactionType::SavingDeposit, '300', '2026-10-07'); // counter deposit
        $this->office(TransactionType::OfficeIncome, '100', '2026-10-08');
        $this->row($loan, TransactionType::LoanDisbursement, '700', '2026-10-09');
        $this->row($saving, TransactionType::SavingWithdrawal, '50', '2026-10-10');
        $this->office(TransactionType::OfficeExpense, '25', '2026-10-11');

        $report = app(ReportService::class)->cashReconciliation(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-12'));

        $this->assertCount(1, $report['officers']);
        $line = $report['officers'][0];
        $this->assertSame(
            [60000, 60000, 50000, 70000],
            [$line['opening']->poisha, $line['collected']->poisha, $line['handed_over']->poisha, $line['closing']->poisha],
        );
        $this->assertSame($line['opening']->plus($line['collected'])->minus($line['handed_over'])->poisha, $line['closing']->poisha);
        $this->assertSame(70000, $report['officers_closing']->poisha);

        $office = $report['office'];
        $this->assertSame(40000, $office['opening']->poisha); // handover 400 before the period
        $this->assertSame(50000, $office['handovers']->poisha);
        $this->assertSame(Money::fromTaka('400')->poisha, $office['other_in']->poisha); // counter 300 + income 100
        $this->assertSame(Money::fromTaka('775')->poisha, $office['out']->poisha); // 700 + 50 + 25
        $this->assertSame(
            $office['opening']->plus($office['handovers'])->plus($office['other_in'])->minus($office['out'])->poisha,
            $office['closing']->poisha,
        );
        $this->assertSame(52500, $office['closing']->poisha); // 400 + 500 + 400 - 775
    }

    public function test_page_renders_and_validates_the_period(): void
    {
        $this->handover('100', '2026-10-02');

        $this->actingAs(User::factory()->withRole(Role::Accountant)->create())
            ->get(route('admin.reports.cash-reconciliation'))
            ->assertOk()->assertSee('Alice Officer')->assertSee('১০০.০০');
        $this->get(route('admin.reports.cash-reconciliation', ['from' => '2026-10-10', 'to' => '2026-10-01']))
            ->assertSessionHasErrors('to');
    }

    private function row(Account $account, TransactionType $type, string $taka, string $date, ?User $officer = null, string $method = 'cash'): Transaction
    {
        return Transaction::factory()->of($account, $type, $taka)->create([
            'field_officer_id' => $officer?->id,
            'payment_method' => $method,
            'transaction_date' => $date,
        ]);
    }

    private function office(TransactionType $type, string $taka, string $date): Transaction
    {
        return Transaction::factory()->office($type, $taka)->create(['payment_method' => 'cash', 'transaction_date' => $date]);
    }

    private function handover(string $taka, string $date): CashHandover
    {
        return CashHandover::factory()->create([
            'field_officer_id' => $this->alice->id,
            'amount' => Money::fromTaka($taka),
            'handover_date' => $date,
        ]);
    }
}
