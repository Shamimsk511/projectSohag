<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\ScheduleStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CollectionSchedule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\TransactionService;
use App\Support\Money;
use App\Support\TransactionData;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Collections settling schedule rows through TransactionService.
 */
class CollectionRecordingTest extends TestCase
{
    use RefreshDatabase;

    private TransactionService $service;

    private User $officer;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
        $this->service = app(TransactionService::class);
        $this->officer = User::factory()->withRole(Role::FieldOfficer)->create();
        $this->manager = User::factory()->withRole(Role::Manager)->create();
    }

    public function test_partial_then_full_collection_moves_the_schedule_to_paid(): void
    {
        $schedule = $this->schedule(Account::factory()->loan()->create(), '500');

        $first = $this->collect($schedule, TransactionType::LoanCollection, '200');

        $this->assertSame($schedule->id, $first->schedule_id);
        $this->assertSame($this->officer->id, $first->field_officer_id);
        $this->assertSame(ScheduleStatus::PartiallyPaid, $schedule->fresh()?->status);

        $this->collect($schedule, TransactionType::LoanCollection, '300');

        $this->assertSame(ScheduleStatus::Paid, $schedule->fresh()?->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'schedule.status_changed')->where('auditable_id', $schedule->id)
            ->where('after->status', 'paid')->count());
    }

    public function test_collecting_more_than_expected_within_outstanding_marks_paid(): void
    {
        $schedule = $this->schedule(Account::factory()->create(), '100');

        $this->collect($schedule, TransactionType::SavingDeposit, '250');

        $this->assertSame(ScheduleStatus::Paid, $schedule->fresh()?->status);
    }

    public function test_reversal_recomputes_the_schedule_status(): void
    {
        $schedule = $this->schedule(Account::factory()->loan()->create(), '500');
        $partial = $this->collect($schedule, TransactionType::LoanCollection, '200');
        $rest = $this->collect($schedule, TransactionType::LoanCollection, '300');

        $this->service->reverse($rest, 'Wrong amount', $this->manager);
        $this->assertSame(ScheduleStatus::PartiallyPaid, $schedule->fresh()?->status);

        $this->service->reverse($partial, 'Wrong party', $this->manager);
        $this->assertSame(ScheduleStatus::Pending, $schedule->fresh()?->status);
    }

    public function test_rejects_a_schedule_of_another_account(): void
    {
        $schedule = $this->schedule(Account::factory()->loan()->create(), '500');
        $other = Account::factory()->loan()->create();

        $this->assertRejected('schedule_id', fn () => $this->collect($schedule, TransactionType::LoanCollection, '100', $other));
    }

    public function test_rejects_a_type_other_than_the_accounts_collection_type(): void
    {
        $loan = Account::factory()->loan()->create();
        $schedule = $this->schedule($loan, '500');

        $this->assertRejected('schedule_id', fn () => $this->service->record(new TransactionData(
            type: TransactionType::LoanDisbursement,
            amount: Money::fromTaka('100'),
            date: today(config('app.display_timezone')),
            account: $loan,
            schedule: $schedule,
        ), $this->manager));
    }

    public function test_rejects_cancelled_and_paid_schedules(): void
    {
        $cancelled = $this->schedule(Account::factory()->loan()->create(), '500', ScheduleStatus::Cancelled);
        $paid = $this->schedule(Account::factory()->loan()->create(), '500', ScheduleStatus::Paid);

        $this->assertRejected('schedule_id', fn () => $this->collect($cancelled, TransactionType::LoanCollection, '100'));
        $this->assertRejected('schedule_id', fn () => $this->collect($paid, TransactionType::LoanCollection, '100'));
    }

    public function test_officer_cannot_collect_more_than_outstanding(): void
    {
        $schedule = $this->schedule(Account::factory()->loan('1000')->create(), '500');

        $this->assertRejected('amount', fn () => $this->collect($schedule, TransactionType::LoanCollection, '1500'));
        $this->assertSame(ScheduleStatus::Pending, $schedule->fresh()?->status);
    }

    public function test_collected_for_schedules_ignores_reversed_rows(): void
    {
        $schedule = $this->schedule(Account::factory()->loan()->create(), '500');
        $empty = $this->schedule(Account::factory()->loan()->create(), '500');
        $kept = $this->collect($schedule, TransactionType::LoanCollection, '200');
        $this->service->reverse($this->collect($schedule, TransactionType::LoanCollection, '100'), 'Typo', $this->manager);

        $collected = app(LedgerService::class)->collectedForSchedules([$schedule->id, $empty->id]);

        $this->assertSame($kept->amount->poisha, $collected[$schedule->id]->poisha);
        $this->assertSame(0, $collected[$empty->id]->poisha);
    }

    private function schedule(Account $account, string $expectedTaka, ScheduleStatus $status = ScheduleStatus::Pending): CollectionSchedule
    {
        return CollectionSchedule::factory()->create([
            'account_id' => $account->id,
            'field_officer_id' => $this->officer->id,
            'expected_amount' => Money::fromTaka($expectedTaka),
            'status' => $status,
        ]);
    }

    private function collect(CollectionSchedule $schedule, TransactionType $type, string $taka, ?Account $account = null): Transaction
    {
        return $this->service->record(new TransactionData(
            type: $type,
            amount: Money::fromTaka($taka),
            date: today(config('app.display_timezone')),
            account: $account ?? $schedule->account,
            fieldOfficer: $this->officer,
            schedule: $schedule,
        ), $this->officer);
    }

    /**
     * @param  callable(): mixed  $write
     */
    private function assertRejected(string $field, callable $write): void
    {
        try {
            $write();
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
