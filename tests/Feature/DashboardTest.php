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

class DashboardTest extends TestCase
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

    public function test_dashboard_figures_come_from_the_ledger(): void
    {
        $loan = Account::factory()->loan('10000')->create();
        $otherLoan = Account::factory()->loan('10000')->create();
        $saving = Account::factory()->create();

        $paid = $this->row($this->alice, $loan, '500', ScheduleStatus::Paid);
        $partial = $this->row($this->alice, $otherLoan, '300', ScheduleStatus::PartiallyPaid, '2026-10-12');
        $this->row($this->bob, $saving, '200', ScheduleStatus::Missed);
        $this->row($this->bob, Account::factory()->create(), '999', ScheduleStatus::Cancelled);
        $this->row(null, Account::factory()->create(), '100', ScheduleStatus::Pending);
        $this->row($this->alice, $loan, '700', ScheduleStatus::Pending, '2026-10-13');

        $this->collect($this->alice, $loan, $paid, '600'); // over expected: rate counts the row as 500
        $this->collect($this->alice, $otherLoan, $partial, '100');
        $reversed = $this->collect($this->bob, $saving, null, '50');
        Transaction::factory()->reversing($reversed)->create();
        Transaction::factory()->of($saving, TransactionType::SavingWithdrawal, '20')->create();
        CashHandover::factory()->create(['field_officer_id' => $this->alice->id, 'amount' => Money::fromTaka('150')]);

        $figures = app(ReportService::class)->dashboard(Carbon::parse('2026-10-12'));

        $this->assertSame(Money::fromTaka('1100')->poisha, $figures['expected']->poisha); // 500+300+200+100
        $this->assertSame(Money::fromTaka('700')->poisha, $figures['collected']->poisha); // 600+100
        $this->assertSame(Money::fromTaka('700')->poisha, $figures['received_today']->poisha); // reversed 50 drops out
        $counts = $figures['counts'];
        ksort($counts);
        $this->assertSame(['missed' => 1, 'paid' => 1, 'partially_paid' => 1, 'pending' => 1], $counts);
        $this->assertSame(Money::fromTaka('19300')->poisha, $figures['loan_outstanding']->poisha); // 20000-700
        $this->assertSame(Money::fromTaka('-20')->poisha, $figures['saving_balance']->poisha); // 0 net deposit - 20
        $this->assertSame(Money::fromTaka('550')->poisha, $figures['cash_in_hand']->poisha); // 700 - 150, reversed row gone

        $alice = collect($figures['officers'])->firstWhere('officer.id', $this->alice->id);
        $this->assertSame(2, $alice['rows']);
        $this->assertSame(Money::fromTaka('800')->poisha, $alice['expected']->poisha);
        $this->assertSame(Money::fromTaka('700')->poisha, $alice['collected']->poisha);
        $this->assertSame(1, $alice['paid']);
        $this->assertSame(75, $alice['rate']); // (500 capped + 100) / 800
        $this->assertCount(2, $figures['officers']);
    }

    public function test_page_renders_for_office_roles_and_respects_the_date_filter(): void
    {
        $this->row($this->alice, Account::factory()->loan()->create(), '500', ScheduleStatus::Pending);

        foreach ([Role::Admin, Role::Manager, Role::Accountant] as $role) {
            $this->actingAs(User::factory()->withRole($role)->create())
                ->get(route('admin.dashboard'))
                ->assertOk()
                ->assertSee('Alice Officer')
                ->assertSee('৫০০.০০');
        }

        $this->actingAs(User::factory()->withRole(Role::Admin)->create())
            ->get(route('admin.dashboard', ['date' => '2026-10-20']))
            ->assertOk()
            ->assertDontSee('Alice Officer');

        $this->get(route('admin.dashboard', ['date' => 'nonsense']))->assertSessionHasErrors('date');
    }

    public function test_sidebar_dashboard_link_uses_the_translated_label(): void
    {
        $html = $this->actingAs(User::factory()->withRole(Role::Manager)->create())
            ->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~href="'.preg_quote(route('admin.dashboard'), '~').'"[^>]*>\s*<i[^>]*></i>\s*<p>\s*ড্যাশবোর্ড\s*</p>~u', $html);
        $this->assertStringNotContainsString('>dashboard<', $html);
    }

    public function test_field_officer_cannot_open_the_dashboard(): void
    {
        $this->actingAs($this->alice)->get(route('admin.dashboard'))->assertForbidden();
    }

    private function row(?User $officer, Account $account, string $taka, ScheduleStatus $status, string $due = '2026-10-12'): CollectionSchedule
    {
        return CollectionSchedule::factory()->create([
            'account_id' => $account->id,
            'party_id' => $account->party_id,
            'field_officer_id' => $officer?->id,
            'expected_amount' => Money::fromTaka($taka),
            'due_date' => $due,
            'status' => $status,
        ]);
    }

    private function collect(User $officer, Account $account, ?CollectionSchedule $schedule, string $taka): Transaction
    {
        return Transaction::factory()->of($account, $account->isLoan() ? TransactionType::LoanCollection : TransactionType::SavingDeposit, $taka)->create([
            'field_officer_id' => $officer->id,
            'schedule_id' => $schedule?->id,
            'payment_method' => 'cash',
        ]);
    }
}
