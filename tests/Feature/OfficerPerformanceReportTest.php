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

class OfficerPerformanceReportTest extends TestCase
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

    public function test_lines_cover_the_whole_period_per_officer(): void
    {
        $paid = $this->row($this->alice, '500', ScheduleStatus::Paid, '2026-10-05');
        $partial = $this->row($this->alice, '300', ScheduleStatus::PartiallyPaid, '2026-10-06');
        $this->row($this->alice, '200', ScheduleStatus::Missed, '2026-10-07');
        $this->row($this->bob, '400', ScheduleStatus::Paid, '2026-10-12');
        $this->row($this->bob, '999', ScheduleStatus::Cancelled, '2026-10-12');
        $this->row($this->bob, '100', ScheduleStatus::Pending, '2026-09-30'); // before the period
        $this->row(null, '100', ScheduleStatus::Pending, '2026-10-12'); // no officer

        $this->collect($this->alice, $paid, '600'); // capped to 500 for the rate
        $this->collect($this->alice, $partial, '100');
        $reversed = $this->collect($this->alice, $partial, '200');
        Transaction::factory()->reversing($reversed)->create();

        $lines = collect(app(ReportService::class)->officerPerformance(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-12')))
            ->keyBy(fn (array $line): string => $line['officer']->name);

        $alice = $lines['Alice Officer'];
        $this->assertSame([3, 1, 1, 1], [$alice['rows'], $alice['paid'], $alice['partial'], $alice['missed']]);
        $this->assertSame(Money::fromTaka('1000')->poisha, $alice['expected']->poisha);
        $this->assertSame(Money::fromTaka('700')->poisha, $alice['collected']->poisha);
        $this->assertSame(60, $alice['rate']); // (500 + 100) / 1000

        $bob = $lines['Bob Officer'];
        $this->assertSame([1, 0], [$bob['rows'], $bob['collected']->poisha]);
        $this->assertSame(0, $bob['rate']);
        $this->assertCount(2, $lines);
    }

    public function test_page_defaults_to_the_month_so_far_and_validates_the_range(): void
    {
        $this->row($this->alice, '500', ScheduleStatus::Pending, '2026-10-12');
        $this->row($this->bob, '700', ScheduleStatus::Pending, '2026-10-13'); // tomorrow: outside the default

        $this->actingAs(User::factory()->withRole(Role::Manager)->create())
            ->get(route('admin.reports.officer-performance'))
            ->assertOk()
            ->assertSee('Alice Officer')
            ->assertDontSee('Bob Officer');

        $this->get(route('admin.reports.officer-performance', ['from' => '2026-10-10', 'to' => '2026-10-01']))
            ->assertSessionHasErrors('to');
    }

    private function row(?User $officer, string $taka, ScheduleStatus $status, string $due): CollectionSchedule
    {
        $account = Account::factory()->loan()->create();

        return CollectionSchedule::factory()->create([
            'account_id' => $account->id,
            'party_id' => $account->party_id,
            'field_officer_id' => $officer?->id,
            'expected_amount' => Money::fromTaka($taka),
            'due_date' => $due,
            'status' => $status,
        ]);
    }

    private function collect(User $officer, CollectionSchedule $schedule, string $taka): Transaction
    {
        return Transaction::factory()->of($schedule->account, TransactionType::LoanCollection, $taka)->create([
            'field_officer_id' => $officer->id,
            'schedule_id' => $schedule->id,
            'payment_method' => 'cash',
        ]);
    }
}
