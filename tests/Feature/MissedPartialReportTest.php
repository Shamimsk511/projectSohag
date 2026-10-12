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

class MissedPartialReportTest extends TestCase
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

    public function test_rows_carry_collected_and_remaining_from_the_ledger(): void
    {
        $missed = $this->row($this->alice, '400', ScheduleStatus::Missed, '2026-10-05');
        $partial = $this->row($this->bob, '300', ScheduleStatus::PartiallyPaid, '2026-10-06');
        $this->row($this->alice, '200', ScheduleStatus::Paid, '2026-10-07');
        $this->row($this->alice, '200', ScheduleStatus::Pending, '2026-10-08');
        $this->row($this->alice, '900', ScheduleStatus::Missed, '2026-09-01'); // before the period

        $this->collect($partial, '100');
        $reversed = $this->collect($partial, '50');
        Transaction::factory()->reversing($reversed)->create();

        $report = app(ReportService::class)->missedAndPartial(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-12'));

        $this->assertSame([$missed->id, $partial->id], array_map(fn (array $row): int => $row['schedule']->id, $report['rows']));
        $this->assertSame(Money::fromTaka('700')->poisha, $report['expected']->poisha);
        $this->assertSame(Money::fromTaka('100')->poisha, $report['collected']->poisha);
        $this->assertSame(Money::fromTaka('600')->poisha, $report['remaining']->poisha); // 400 + (300-100)

        $onlyPartial = app(ReportService::class)->missedAndPartial(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-12'), ScheduleStatus::PartiallyPaid);
        $this->assertCount(1, $onlyPartial['rows']);
        $mine = app(ReportService::class)->missedAndPartial(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-12'), null, $this->alice->id);
        $this->assertSame([$missed->id], array_map(fn (array $row): int => $row['schedule']->id, $mine['rows']));
    }

    public function test_page_defaults_to_the_last_thirty_days_and_validates_filters(): void
    {
        $inside = $this->row($this->alice, '400', ScheduleStatus::Missed, '2026-10-05');
        $outside = $this->row($this->bob, '400', ScheduleStatus::Missed, '2026-08-01'); // outside the default window

        $this->actingAs(User::factory()->withRole(Role::Manager)->create())
            ->get(route('admin.reports.missed-partial'))
            ->assertOk()->assertSee($inside->account->account_number)->assertDontSee($outside->account->account_number);

        $this->get(route('admin.reports.missed-partial', ['status' => 'paid']))->assertSessionHasErrors('status');
        $this->get(route('admin.reports.missed-partial', ['from' => '2026-10-10', 'to' => '2026-10-01']))->assertSessionHasErrors('to');
    }

    private function row(User $officer, string $taka, ScheduleStatus $status, string $due): CollectionSchedule
    {
        $account = Account::factory()->loan()->create();

        return CollectionSchedule::factory()->create([
            'account_id' => $account->id,
            'party_id' => $account->party_id,
            'field_officer_id' => $officer->id,
            'expected_amount' => Money::fromTaka($taka),
            'due_date' => $due,
            'status' => $status,
        ]);
    }

    private function collect(CollectionSchedule $schedule, string $taka): Transaction
    {
        return Transaction::factory()->of($schedule->account, TransactionType::LoanCollection, $taka)->create([
            'schedule_id' => $schedule->id,
            'payment_method' => 'cash',
        ]);
    }
}
