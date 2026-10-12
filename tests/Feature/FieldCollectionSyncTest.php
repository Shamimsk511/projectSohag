<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\ScheduleStatus;
use App\Models\Account;
use App\Models\CollectionSchedule;
use App\Models\DailyClosing;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FieldCollectionSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00'); // 12:00 in Dhaka
        $this->officer = User::factory()->withRole(Role::FieldOfficer)->create();
    }

    public function test_item_is_recorded_once_and_a_resend_returns_the_same_receipt(): void
    {
        $schedule = $this->schedule('500');
        $item = $this->item($schedule, '500');

        $first = $this->sync([$item])->assertOk()->json('results.0');
        $this->assertSame('created', $first['status']);
        $this->assertSame($item['client_uuid'], $first['client_uuid']);
        $transaction = Transaction::query()->sole();
        $this->assertSame($transaction->id, $first['transaction_id']);
        $this->assertSame($transaction->receipt_no, $first['receipt_no']);
        $this->assertSame(route('field.receipts.show', $transaction, false), $first['receipt_url']);
        $this->assertSame(Money::fromTaka('500')->format(), $first['amount_label']);
        $this->assertSame($this->officer->id, $transaction->field_officer_id);
        $this->assertSame($schedule->id, $transaction->schedule_id);
        $this->assertSame('2026-10-12', $transaction->transaction_date->toDateString());
        $this->assertSame(ScheduleStatus::Paid, $schedule->fresh()?->status);

        // Response lost, row reassigned meanwhile: the resend still gets its receipt back.
        $schedule->update(['field_officer_id' => User::factory()->withRole(Role::FieldOfficer)->create()->id]);
        $second = $this->sync([$item])->assertOk()->json('results.0');
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame($transaction->receipt_no, $second['receipt_no']);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_reused_uuid_with_a_different_payload_is_a_conflict_that_reveals_nothing(): void
    {
        $theirs = $this->schedule('500', ['field_officer_id' => User::factory()->withRole(Role::FieldOfficer)->create()->id]);
        $uuid = (string) Str::uuid();
        $transaction = Transaction::factory()->create(['client_uuid' => $uuid, 'schedule_id' => $theirs->id, 'receipt_no' => 'R-202610-00009']);
        $mine = $this->schedule('500');

        $result = $this->sync([[...$this->item($mine, '500'), 'client_uuid' => $uuid]])->assertOk()->json('results.0');

        $this->assertSame(['client_uuid' => $uuid, 'status' => 'rejected', 'code' => 'uuid_conflict', 'message' => __('app.sync.uuid_conflict'), 'errors' => []], $result);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertNotNull($transaction->fresh());
    }

    public function test_items_are_processed_in_order_and_one_failure_does_not_block_the_rest(): void
    {
        $schedule = $this->schedule('500', ['account_id' => Account::factory()->loan('500')->create()->id]);
        $other = $this->schedule('100');

        $results = $this->sync([
            $this->item($schedule, '300', '2026-10-12T09:00:00+06:00'),
            $this->item($schedule, '300', '2026-10-12T10:00:00+06:00'), // only 200 still outstanding
            $this->item($other, 'abc'),
            $this->item($other, '100'),
        ])->assertOk()->json('results');

        $this->assertSame(['created', 'rejected', 'rejected', 'created'], array_column($results, 'status'));
        $this->assertSame('exceeds_outstanding', $results[1]['code']);
        $this->assertSame('invalid', $results[2]['code']);
        $this->assertArrayHasKey('amount', $results[2]['errors']);
        $this->assertSame(2, Transaction::query()->count());
    }

    public function test_rows_the_officer_does_not_own_now_are_forbidden(): void
    {
        $theirs = $this->schedule('100', ['field_officer_id' => User::factory()->withRole(Role::FieldOfficer)->create()->id]);
        $missing = [...$this->item($theirs, '100'), 'schedule_id' => 999999];

        $results = $this->sync([$this->item($theirs, '100'), $missing])->assertOk()->json('results');

        $this->assertSame(['forbidden', 'forbidden'], array_column($results, 'code'));
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_collection_is_dated_by_the_device_within_one_day_back(): void
    {
        $schedule = $this->schedule('100', ['due_date' => '2026-10-11', 'status' => ScheduleStatus::Missed]);
        $schedule->account->update(['opened_at' => '2026-10-01']);

        $results = $this->sync([
            $this->item($schedule, '10', '2026-10-10T23:59:00+06:00'),
            $this->item($schedule, '10', '2026-10-13T00:01:00+06:00'),
            $this->item($schedule, '10', '2026-10-10T18:30:00Z'), // 00:30 on the 11th in Dhaka
        ])->assertOk()->json('results');

        $this->assertSame(['rejected', 'rejected', 'created'], array_column($results, 'status'));
        $this->assertSame(['date_out_of_range', 'date_out_of_range'], [$results[0]['code'], $results[1]['code']]);
        $this->assertSame('2026-10-11', Transaction::query()->sole()->transaction_date->toDateString());
    }

    public function test_closed_day_is_rejected(): void
    {
        DailyClosing::factory()->create(['date' => '2026-10-12']);

        $result = $this->sync([$this->item($this->schedule('100'), '100')])->assertOk()->json('results.0');

        $this->assertSame(['rejected', 'day_closed'], [$result['status'], $result['code']]);
        $this->assertSame(__('app.transactions.day_closed'), $result['message']);
    }

    public function test_malformed_batches_are_refused_whole(): void
    {
        $item = $this->item($this->schedule('100'), '100');

        $this->sync([])->assertUnprocessable();
        $this->sync([$item, $item])->assertUnprocessable()->assertJsonValidationErrors('items.1.client_uuid');
        $this->sync(array_fill(0, 51, $item))->assertUnprocessable();
        $this->sync([[...$item, 'client_uuid' => 'not-a-uuid']])->assertUnprocessable();
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_only_signed_in_field_officers_can_sync(): void
    {
        $item = $this->item($this->schedule('100'), '100');

        $this->postJson(route('field.api.collections.sync'), ['items' => [$item]])->assertUnauthorized();
        $this->actingAs(User::factory()->withRole(Role::Manager)->create())
            ->postJson(route('field.api.collections.sync'), ['items' => [$item]])
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function schedule(string $expectedTaka, array $attributes = []): CollectionSchedule
    {
        return CollectionSchedule::factory()->create([
            'field_officer_id' => $this->officer->id,
            'expected_amount' => Money::fromTaka($expectedTaka),
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(CollectionSchedule $schedule, string $amount, string $collectedAt = '2026-10-12T11:00:00+06:00'): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'schedule_id' => $schedule->id,
            'amount' => $amount,
            'payment_method' => 'cash',
            'description' => null,
            'collected_at' => $collectedAt,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function sync(array $items): TestResponse
    {
        return $this->actingAs($this->officer)->postJson(route('field.api.collections.sync'), ['items' => $items]);
    }
}
