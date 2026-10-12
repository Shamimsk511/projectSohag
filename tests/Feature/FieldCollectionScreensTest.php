<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\ScheduleStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\CollectionSchedule;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class FieldCollectionScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
        $this->officer = User::factory()->withRole(Role::FieldOfficer)->create();
    }

    public function test_day_list_shows_only_the_officers_rows_due_today_with_totals(): void
    {
        $mine = $this->schedule(Account::factory()->loan()->create(), '500');
        $paid = $this->schedule(Account::factory()->create(), '200');
        $this->collect($paid, '200');
        $cancelled = $this->schedule(Account::factory()->create(), '300', ['status' => ScheduleStatus::Cancelled]);
        $tomorrow = $this->schedule(Account::factory()->create(), '300', ['due_date' => '2026-10-13']);
        $other = CollectionSchedule::factory()->create([
            'field_officer_id' => User::factory()->withRole(Role::FieldOfficer)->create()->id,
        ]);

        $this->actingAs($this->officer)->get(route('field.home'))
            ->assertOk()
            ->assertSee($mine->account->account_number)
            ->assertSee($mine->party->name)
            ->assertSee($paid->account->account_number)
            ->assertSee(route('field.collections.create', $mine))
            ->assertDontSee(route('field.collections.create', $paid))
            ->assertDontSee($cancelled->account->account_number)
            ->assertDontSee($tomorrow->account->account_number)
            ->assertDontSee($other->account->account_number)
            ->assertSeeInOrder(['৭০০.০০', '২০০.০০', '৫০০.০০']);
    }

    public function test_collect_page_shows_due_and_a_fresh_client_uuid(): void
    {
        $schedule = $this->schedule(Account::factory()->loan('10000')->create(), '500');
        $this->collect($schedule, '200');

        $this->actingAs($this->officer)->get(route('field.collections.create', $schedule))
            ->assertOk()
            ->assertSee($schedule->party->name)
            ->assertSee('৯,৮০০.০০')
            ->assertSee('data-full-due="300.00"', false)
            ->assertSee('name="client_uuid"', false);
    }

    public function test_officer_records_a_collection_once_per_client_uuid(): void
    {
        $schedule = $this->schedule(Account::factory()->loan()->create(), '500');
        $payload = ['amount' => '৫০০', 'payment_method' => 'cash', 'client_uuid' => (string) Str::uuid()];

        $this->actingAs($this->officer)->post(route('field.collections.store', $schedule), $payload)
            ->assertRedirect(route('field.home'))
            ->assertSessionHas('status');
        $this->actingAs($this->officer)->post(route('field.collections.store', $schedule), $payload)
            ->assertRedirect(route('field.home'));

        $transaction = Transaction::query()->sole();
        $this->assertSame(TransactionType::LoanCollection, $transaction->type);
        $this->assertSame($this->officer->id, $transaction->field_officer_id);
        $this->assertSame(50000, $transaction->amount->poisha);
        $this->assertSame(ScheduleStatus::Paid, $schedule->fresh()?->status);
    }

    public function test_amount_above_outstanding_is_rejected(): void
    {
        $schedule = $this->schedule(Account::factory()->loan('1000')->create(), '500');

        $this->actingAs($this->officer)
            ->from(route('field.collections.create', $schedule))
            ->post(route('field.collections.store', $schedule), [
                'amount' => '1500', 'payment_method' => 'cash', 'client_uuid' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('field.collections.create', $schedule))
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_officer_cannot_open_or_post_another_officers_row(): void
    {
        $other = CollectionSchedule::factory()->create([
            'field_officer_id' => User::factory()->withRole(Role::FieldOfficer)->create()->id,
        ]);

        $this->actingAs($this->officer)->get(route('field.collections.create', $other))->assertForbidden();
        $this->actingAs($this->officer)->post(route('field.collections.store', $other), [
            'amount' => '100', 'payment_method' => 'cash', 'client_uuid' => (string) Str::uuid(),
        ])->assertForbidden();

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_paid_row_has_no_collect_page(): void
    {
        $schedule = $this->schedule(Account::factory()->create(), '100');
        $this->collect($schedule, '100');

        $this->actingAs($this->officer)->get(route('field.collections.create', $schedule))
            ->assertRedirect(route('field.home'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function schedule(Account $account, string $expectedTaka, array $attributes = []): CollectionSchedule
    {
        return CollectionSchedule::factory()->create([
            'account_id' => $account->id,
            'field_officer_id' => $this->officer->id,
            'expected_amount' => Money::fromTaka($expectedTaka),
            ...$attributes,
        ]);
    }

    private function collect(CollectionSchedule $schedule, string $taka): void
    {
        $this->actingAs($this->officer)->post(route('field.collections.store', $schedule), [
            'amount' => $taka, 'payment_method' => 'cash', 'client_uuid' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
    }
}
