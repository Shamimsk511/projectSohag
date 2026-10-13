<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\ScheduleStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\CollectionSchedule;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountService;
use App\Services\ReportService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardLiveTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
        $this->manager = User::factory()->withRole(Role::Manager)->create();
        $this->officer = User::factory()->withRole(Role::FieldOfficer)->create(['name' => 'Alice Officer']);
    }

    private function day(): Carbon
    {
        return Carbon::parse('2026-10-12');
    }

    public function test_feed_lists_the_days_rows_newest_first_with_who_and_member(): void
    {
        $account = Account::factory()->for(Party::factory()->state(['name' => 'Rahim Member']))->create();

        Carbon::setTestNow('2026-10-12 04:00:00');
        $first = Transaction::factory()->of($account, TransactionType::SavingDeposit, '100')->create(['field_officer_id' => $this->officer->id]);
        Carbon::setTestNow('2026-10-12 05:00:00');
        $second = Transaction::factory()->of($account, TransactionType::SavingWithdrawal, '40')->create(['created_by' => $this->manager->id]);
        Transaction::factory()->of($account, TransactionType::SavingDeposit, '999')->create(['transaction_date' => '2026-10-11']);
        Carbon::setTestNow('2026-10-12 06:00:00');

        $feed = app(ReportService::class)->activity($this->day());

        $this->assertSame([$second->id, $first->id], $feed->pluck('id')->all());
        $this->assertSame('Rahim Member', $feed[0]->party?->name);
    }

    public function test_feed_is_capped(): void
    {
        $account = Account::factory()->create();
        Transaction::factory()->count(5)->of($account, TransactionType::SavingDeposit, '10')->create();

        $this->assertCount(3, app(ReportService::class)->activity($this->day(), 3));
    }

    public function test_loans_issued_that_day_show_principal_and_agreed_extra(): void
    {
        $accounts = app(AccountService::class);
        $accounts->openLoan(Party::factory()->create(), Money::fromTaka('50000'), Money::fromTaka('8250'), PaymentMethod::Cash, $this->day(), $this->manager);
        $accounts->openLoan(Party::factory()->create(), Money::fromTaka('20000'), Money::zero(), PaymentMethod::Cash, $this->day(), $this->manager);
        $accounts->openLoan(Party::factory()->create(), Money::fromTaka('1000'), Money::fromTaka('100'), PaymentMethod::Cash, Carbon::parse('2026-10-11'), $this->manager);

        $loans = app(ReportService::class)->dashboard($this->day())['loans'];

        $this->assertSame(2, $loans['count']);
        $this->assertSame(Money::fromTaka('70000')->poisha, $loans['principal']->poisha);
        $this->assertSame(Money::fromTaka('8250')->poisha, $loans['extra']->poisha);
    }

    public function test_office_income_and_expense_for_the_day(): void
    {
        Transaction::factory()->office(TransactionType::OfficeIncome, '700')->create();
        Transaction::factory()->office(TransactionType::OfficeExpense, '200')->create();
        Transaction::factory()->office(TransactionType::OfficeIncome, '5')->create(['transaction_date' => '2026-10-11']);

        $office = app(ReportService::class)->dashboard($this->day())['office'];

        $this->assertSame(Money::fromTaka('700')->poisha, $office['income']->poisha);
        $this->assertSame(Money::fromTaka('200')->poisha, $office['expense']->poisha);
    }

    public function test_officer_line_has_cash_in_hand_and_last_collection_time(): void
    {
        $loan = Account::factory()->loan('10000')->create();
        $row = CollectionSchedule::factory()->create([
            'account_id' => $loan->id, 'field_officer_id' => $this->officer->id, 'due_date' => '2026-10-12',
            'expected_amount' => Money::fromTaka('500'), 'status' => ScheduleStatus::Pending,
        ]);
        $idle = User::factory()->withRole(Role::FieldOfficer)->create();
        CollectionSchedule::factory()->create(['field_officer_id' => $idle->id, 'due_date' => '2026-10-12']);

        Carbon::setTestNow('2026-10-12 05:30:00');
        Transaction::factory()->of($loan, TransactionType::LoanCollection, '300')->create([
            'field_officer_id' => $this->officer->id, 'schedule_id' => $row->id, 'payment_method' => PaymentMethod::Cash,
        ]);

        $lines = collect(app(ReportService::class)->dashboard($this->day())['officers']);
        $alice = $lines->firstWhere('officer.id', $this->officer->id);

        $this->assertSame(Money::fromTaka('300')->poisha, $alice['cash']->poisha);
        $this->assertSame('2026-10-12 05:30:00', $alice['last_at']?->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($lines->firstWhere('officer.id', $idle->id)['last_at']);
    }

    public function test_live_endpoint_returns_the_refreshable_fragment_for_office_roles_only(): void
    {
        $account = Account::factory()->for(Party::factory()->state(['name' => 'Karim Member']))->create();
        Transaction::factory()->of($account, TransactionType::SavingDeposit, '100')->create();

        $this->actingAs($this->manager)->get(route('admin.dashboard.live', ['date' => '2026-10-12']))
            ->assertOk()
            ->assertSee('Karim Member')
            ->assertDontSee('<html', false);
        $this->actingAs($this->officer)->get(route('admin.dashboard.live'))->assertForbidden();
    }

    public function test_page_marks_the_live_area_only_for_today(): void
    {
        $live = route('admin.dashboard.live', ['date' => '2026-10-12']);

        $this->actingAs($this->manager)->get(route('admin.dashboard', ['date' => '2026-10-12']))
            ->assertOk()->assertSee('data-live-url="'.$live.'"', false)->assertSee('data-live-refresh="1"', false);
        $this->actingAs($this->manager)->get(route('admin.dashboard', ['date' => '2026-10-10']))
            ->assertOk()->assertSee('data-live-refresh="0"', false);
    }
}
