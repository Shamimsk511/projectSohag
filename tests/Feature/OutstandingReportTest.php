<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\ScheduleStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Area;
use App\Models\CollectionSchedule;
use App\Models\Party;
use App\Models\PartyAssignment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReportService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OutstandingReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
    }

    public function test_lines_show_outstanding_and_overdue_from_the_ledger(): void
    {
        $today = Carbon::parse('2026-10-12');
        $late = Account::factory()->loan('10000')->create();
        $onTime = Account::factory()->loan('5000')->create();
        $settled = Account::factory()->loan('1000')->create();
        Account::factory()->loan('9000')->closed()->create();
        Account::factory()->create(); // saving account

        $this->due($late, '2026-10-01', '400', ScheduleStatus::Missed);
        $partial = $this->due($late, '2026-10-02', '300', ScheduleStatus::PartiallyPaid);
        $this->due($late, '2026-10-03', '200', ScheduleStatus::Paid); // paid: not overdue
        $this->due($late, '2026-10-12', '100', ScheduleStatus::Pending); // due today: not overdue yet
        $this->due($onTime, '2026-10-20', '100', ScheduleStatus::Pending);

        $this->collect($late, $partial, '100');
        $this->collect($late, null, '600');
        $reversed = $this->collect($late, null, '777');
        Transaction::factory()->reversing($reversed)->create();
        $this->collect($settled, null, '1000');

        $report = app(ReportService::class)->outstanding($today);

        $this->assertCount(2, $report['lines']); // settled and closed accounts drop out
        [$first, $second] = $report['lines'];
        $this->assertSame($late->id, $first['account']->id);
        $this->assertSame(Money::fromTaka('9300')->poisha, $first['outstanding']->poisha); // 10000-700
        $this->assertSame(Money::fromTaka('600')->poisha, $first['overdue']->poisha); // 400 + (300-100)
        $this->assertSame(2, $first['overdue_rows']);
        $this->assertSame('2026-10-01', $first['oldest_due']?->toDateString());
        $this->assertSame($onTime->id, $second['account']->id);
        $this->assertTrue($second['overdue']->isZero());
        $this->assertSame(Money::fromTaka('14300')->poisha, $report['outstanding']->poisha);
        $this->assertSame(Money::fromTaka('600')->poisha, $report['overdue']->poisha);

        $this->assertCount(1, app(ReportService::class)->outstanding($today, null, true)['lines']);
    }

    public function test_area_filter_and_page(): void
    {
        $north = Area::factory()->create(['name' => 'North Zone']);
        $south = Area::factory()->create(['name' => 'South Zone']);
        $officer = User::factory()->withRole(Role::FieldOfficer)->create(['name' => 'Carol Officer']);
        $inNorth = Account::factory()->loan()->create(['party_id' => Party::factory()->create(['area_id' => $north->id, 'name' => 'Nora North'])->id]);
        Account::factory()->loan()->create(['party_id' => Party::factory()->create(['area_id' => $south->id, 'name' => 'Sam South'])->id]);
        PartyAssignment::factory()->create(['party_id' => $inNorth->party_id, 'field_officer_id' => $officer->id, 'assigned_until' => null]);

        $this->actingAs(User::factory()->withRole(Role::Accountant)->create())
            ->get(route('admin.reports.outstanding'))
            ->assertOk()->assertSee('Nora North')->assertSee('Sam South')->assertSee('Carol Officer');

        $this->get(route('admin.reports.outstanding', ['area' => $north->id]))
            ->assertOk()->assertSee('Nora North')->assertDontSee('Sam South');
        $this->get(route('admin.reports.outstanding', ['area' => 99999]))->assertSessionHasErrors('area');
    }

    private function due(Account $account, string $date, string $taka, ScheduleStatus $status): CollectionSchedule
    {
        return CollectionSchedule::factory()->create([
            'account_id' => $account->id,
            'party_id' => $account->party_id,
            'due_date' => $date,
            'expected_amount' => Money::fromTaka($taka),
            'status' => $status,
        ]);
    }

    private function collect(Account $account, ?CollectionSchedule $schedule, string $taka): Transaction
    {
        return Transaction::factory()->of($account, TransactionType::LoanCollection, $taka)->create([
            'schedule_id' => $schedule?->id,
            'payment_method' => 'cash',
        ]);
    }
}
