<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReportService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DailyCollectionReportTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
        $this->alice = User::factory()->withRole(Role::FieldOfficer)->create(['name' => 'Alice Officer']);
        $this->bob = User::factory()->withRole(Role::FieldOfficer)->create(['name' => 'Bob Officer']);
    }

    public function test_report_lists_effective_receipts_of_the_day_with_totals(): void
    {
        $loan = Account::factory()->loan()->create();
        $saving = Account::factory()->create();

        $this->receipt($loan, TransactionType::LoanCollection, '500', $this->alice, 'cash');
        $this->receipt($saving, TransactionType::SavingDeposit, '200', $this->alice, 'bank');
        $this->receipt($saving, TransactionType::SavingDeposit, '300', $this->bob, 'cash');
        $reversed = $this->receipt($loan, TransactionType::LoanCollection, '999', $this->bob, 'cash');
        Transaction::factory()->reversing($reversed)->create();
        $this->receipt($loan, TransactionType::LoanCollection, '111', $this->bob, 'cash', '2026-10-11');
        Transaction::factory()->of($saving, TransactionType::SavingWithdrawal, '50')->create();

        $report = app(ReportService::class)->dailyCollection(Carbon::parse('2026-10-12'));

        $this->assertCount(3, $report['rows']);
        $this->assertSame(Money::fromTaka('1000')->poisha, $report['total']->poisha);
        $this->assertSame(
            [['Alice Officer', 2, 70000], ['Bob Officer', 1, 30000]],
            array_map(fn (array $line): array => [$line['name'], $line['count'], $line['total']->poisha], $report['by_officer']),
        );
        $methods = collect($report['by_method'])->mapWithKeys(fn (array $line): array => [$line['method']?->value => $line['total']->poisha]);
        $this->assertSame(['cash' => 80000, 'bank' => 20000], $methods->all());

        $mine = app(ReportService::class)->dailyCollection(Carbon::parse('2026-10-12'), $this->alice->id);
        $this->assertSame(Money::fromTaka('700')->poisha, $mine['total']->poisha);
    }

    public function test_page_shows_rows_and_respects_filters(): void
    {
        $account = Account::factory()->loan()->create();
        $row = $this->receipt($account, TransactionType::LoanCollection, '500', $this->alice, 'cash');
        $row->forceFill(['receipt_no' => 'R-202610-00007'])->saveQuietly();

        $accountant = User::factory()->withRole(Role::Accountant)->create();
        $this->actingAs($accountant)->get(route('admin.reports.daily-collection'))
            ->assertOk()
            ->assertSee('R-202610-00007')
            ->assertSee($account->account_number)
            ->assertSee('Alice Officer')
            ->assertSee('৫০০.০০');

        $this->get(route('admin.reports.daily-collection', ['officer' => $this->bob->id]))
            ->assertOk()->assertDontSee('R-202610-00007');
        $this->get(route('admin.reports.daily-collection', ['date' => '2026-10-11']))
            ->assertOk()->assertDontSee('R-202610-00007');
        $this->get(route('admin.reports.daily-collection', ['date' => 'bad']))->assertSessionHasErrors('date');
    }

    private function receipt(Account $account, TransactionType $type, string $taka, User $officer, string $method, string $date = '2026-10-12'): Transaction
    {
        return Transaction::factory()->of($account, $type, $taka)->create([
            'field_officer_id' => $officer->id,
            'payment_method' => $method,
            'transaction_date' => $date,
        ]);
    }
}
