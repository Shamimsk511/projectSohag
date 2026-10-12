<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\ScheduleStatus;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CollectionSchedule;
use App\Models\PartyAssignment;
use App\Models\User;
use App\Services\CollectionService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScheduleEditingTest extends TestCase
{
    use RefreshDatabase;

    private CollectionService $service;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->service = app(CollectionService::class);
        $this->manager = User::factory()->withRole(Role::Manager)->create();
        Carbon::setTestNow('2026-10-12 06:00:00');
    }

    private function expectValidation(string $field, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_adds_a_custom_date_with_the_assigned_officer(): void
    {
        $account = Account::factory()->create();
        $assignment = PartyAssignment::factory()->create(['party_id' => $account->party_id]);

        $schedule = $this->service->addSchedule($account, Carbon::parse('2026-10-15'), Money::fromTaka('250'), 'Market day', $this->manager);

        $this->assertSame('2026-10-15', $schedule->due_date->toDateString());
        $this->assertSame($assignment->field_officer_id, $schedule->field_officer_id);
        $this->assertSame(25000, $schedule->expected_amount->poisha);
        $this->assertSame('Market day', $schedule->notes);
        $this->assertSame('schedule.added', AuditLog::sole()->action);
    }

    public function test_custom_date_rejects_past_date_clash_and_closed_account(): void
    {
        $account = Account::factory()->create();
        CollectionSchedule::factory()->create(['account_id' => $account->id, 'due_date' => '2026-10-15']);

        $this->expectValidation('due_date', fn () => $this->service->addSchedule($account, Carbon::parse('2026-10-11'), Money::fromTaka('1'), null, $this->manager));
        $this->expectValidation('due_date', fn () => $this->service->addSchedule($account, Carbon::parse('2026-10-15'), Money::fromTaka('1'), null, $this->manager));
        $this->expectValidation('due_date', fn () => $this->service->addSchedule(Account::factory()->closed()->create(), Carbon::parse('2026-10-15'), Money::fromTaka('1'), null, $this->manager));
        $this->expectValidation('expected_amount', fn () => $this->service->addSchedule($account, Carbon::parse('2026-10-16'), Money::zero(), null, $this->manager));
    }

    public function test_updates_a_pending_row_and_logs_the_change(): void
    {
        $schedule = CollectionSchedule::factory()->create(['due_date' => '2026-10-13']);

        $this->service->updateSchedule($schedule, Carbon::parse('2026-10-14'), Money::fromTaka('80'), 'Moved', $this->manager);

        $schedule->refresh();
        $this->assertSame('2026-10-14', $schedule->due_date->toDateString());
        $this->assertSame(8000, $schedule->expected_amount->poisha);
        $this->assertSame('Moved', $schedule->notes);
        $this->assertSame('schedule.updated', AuditLog::sole()->action);
    }

    public function test_update_rejects_non_pending_past_and_clashing_dates(): void
    {
        $schedule = CollectionSchedule::factory()->create(['due_date' => '2026-10-13']);
        CollectionSchedule::factory()->create(['account_id' => $schedule->account_id, 'due_date' => '2026-10-14']);
        $paid = CollectionSchedule::factory()->create(['status' => ScheduleStatus::Paid]);

        $this->expectValidation('due_date', fn () => $this->service->updateSchedule($schedule, Carbon::parse('2026-10-14'), Money::fromTaka('1'), null, $this->manager));
        $this->expectValidation('due_date', fn () => $this->service->updateSchedule($schedule, Carbon::parse('2026-10-11'), Money::fromTaka('1'), null, $this->manager));
        $this->expectValidation('status', fn () => $this->service->updateSchedule($paid, Carbon::parse('2026-10-20'), Money::fromTaka('1'), null, $this->manager));

        // Keeping the same date is not a clash with itself.
        $this->service->updateSchedule($schedule, Carbon::parse('2026-10-13'), Money::fromTaka('5'), null, $this->manager);
        $this->assertSame(500, $schedule->refresh()->expected_amount->poisha);
    }

    public function test_cancels_a_pending_row_with_reason(): void
    {
        $schedule = CollectionSchedule::factory()->create();
        $missed = CollectionSchedule::factory()->create(['status' => ScheduleStatus::Missed]);

        $this->service->cancelSchedule($schedule, 'Party travelling', $this->manager);

        $schedule->refresh();
        $this->assertSame(ScheduleStatus::Cancelled, $schedule->status);
        $this->assertSame('Party travelling', $schedule->notes);
        $this->assertSame('schedule.cancelled', AuditLog::sole()->action);
        $this->expectValidation('status', fn () => $this->service->cancelSchedule($missed, 'x', $this->manager));
    }

    public function test_reassigns_one_officers_pending_rows_for_a_day(): void
    {
        $from = User::factory()->withRole(Role::FieldOfficer)->create();
        $to = User::factory()->withRole(Role::FieldOfficer)->create();
        $moved = CollectionSchedule::factory()->count(2)->create(['field_officer_id' => $from->id, 'due_date' => '2026-10-12']);
        $paid = CollectionSchedule::factory()->create(['field_officer_id' => $from->id, 'due_date' => '2026-10-12', 'status' => ScheduleStatus::Paid]);
        $otherDay = CollectionSchedule::factory()->create(['field_officer_id' => $from->id, 'due_date' => '2026-10-13']);
        $unassigned = CollectionSchedule::factory()->create(['due_date' => '2026-10-12']);

        $this->assertSame(2, $this->service->reassignDay(Carbon::parse('2026-10-12'), $from, $to, $this->manager));
        $this->assertSame(1, $this->service->reassignDay(Carbon::parse('2026-10-12'), null, $to, $this->manager));

        foreach ([...$moved, $unassigned] as $schedule) {
            $this->assertSame($to->id, $schedule->refresh()->field_officer_id);
        }
        $this->assertSame($from->id, $paid->refresh()->field_officer_id);
        $this->assertSame($from->id, $otherDay->refresh()->field_officer_id);
        $this->assertSame(3, AuditLog::where('action', 'schedule.reassigned')->count());
    }

    public function test_reassign_needs_an_active_field_officer_and_a_current_date(): void
    {
        $from = User::factory()->withRole(Role::FieldOfficer)->create();

        $this->expectValidation('to_officer_id', fn () => $this->service->reassignDay(Carbon::parse('2026-10-12'), $from, $this->manager, $this->manager));
        $this->expectValidation('to_officer_id', fn () => $this->service->reassignDay(Carbon::parse('2026-10-12'), $from, $from, $this->manager));
        $this->expectValidation('date', fn () => $this->service->reassignDay(Carbon::parse('2026-10-11'), $from, User::factory()->withRole(Role::FieldOfficer)->create(), $this->manager));
    }
}
