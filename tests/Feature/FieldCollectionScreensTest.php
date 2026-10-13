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

    public function test_day_list_puts_unpaid_members_first_folds_paid_ones_and_sums_up_the_day(): void
    {
        $paid = $this->schedule(Account::factory()->create(), '200');
        $pending = $this->schedule(Account::factory()->create(), '500');
        $partial = $this->schedule(Account::factory()->loan()->create(), '300');
        $paid->party->update(['name' => 'Anna Paid']);
        $pending->party->update(['name' => 'Zed Pending']);
        $partial->party->update(['name' => 'Mia Partial']);
        $this->collect($paid, '200');
        $this->collect($partial, '100');
        // Second open account of the same member: counted once in "members left today".
        $this->schedule(Account::factory()->for($pending->party)->create(), '50');

        $this->actingAs($this->officer)->get(route('field.home'))
            ->assertOk()
            ->assertSee(__('app.field.open_today', ['count' => '২']))
            ->assertSee(__('app.field.day_line', [
                'paid' => '১', 'total' => '৩',
                'collected' => Money::fromTaka('300')->format(),
                'cash' => Money::fromTaka('300')->format(),
            ]))
            ->assertSeeInOrder(['Mia Partial', 'Zed Pending', __('app.field.paid_section', ['count' => '১']), 'Anna Paid']);
    }

    public function test_day_list_embeds_an_offline_snapshot_of_the_officers_own_rows(): void
    {
        $mine = $this->schedule(Account::factory()->loan()->create(), '500');
        $this->collect($mine, '200');
        $missed = $this->schedule(Account::factory()->create(), '100', ['due_date' => '2026-10-10', 'status' => ScheduleStatus::Missed]);
        $other = CollectionSchedule::factory()->create([
            'field_officer_id' => User::factory()->withRole(Role::FieldOfficer)->create()->id,
        ]);

        $html = $this->actingAs($this->officer)->get(route('field.home'))->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertSame(1, preg_match('#<script type="application/json" id="field-today">(.*?)</script>#s', $html, $match));
        $snapshot = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($this->officer->id, $snapshot['user_id']);
        $this->assertSame('2026-10-12', $snapshot['date']);
        $this->assertFalse($snapshot['today_closed']);
        $this->assertSame(Money::fromTaka('500')->format(), $snapshot['summary']['expected']);
        $this->assertSame([$mine->id, $missed->id], array_column($snapshot['rows'], 'schedule_id'));
        $this->assertNotContains($other->id, array_column($snapshot['rows'], 'schedule_id'));

        [$today, $earlier] = $snapshot['rows'];
        $this->assertSame($mine->account_id, $today['account_id']);
        $this->assertSame($mine->party->name, $today['party_name']);
        $this->assertSame($mine->account->account_number, $today['account_number']);
        $this->assertFalse($today['earlier']);
        $this->assertTrue($today['collectable']);
        $this->assertSame(ScheduleStatus::PartiallyPaid->value, $today['status']);
        $this->assertSame(['poisha' => 20000, 'label' => Money::fromTaka('200')->format()], $today['collected']);
        $this->assertSame(30000, $today['remaining']['poisha']);
        $this->assertTrue($earlier['earlier']);
        $this->assertSame('2026-10-10', $earlier['due_date']);
    }

    public function test_collect_page_prefills_the_due_and_summarises_the_members_accounts(): void
    {
        $schedule = $this->schedule(Account::factory()->loan('10000')->create(), '500');
        $this->collect($schedule, '200');
        $saving = Account::factory()->for($schedule->party)->create();
        $this->schedule($saving, '100');
        $this->collect(CollectionSchedule::where('account_id', $saving->id)->firstOrFail(), '100');
        $closed = Account::factory()->for($schedule->party)->create(['closed_at' => now()]);

        $this->actingAs($this->officer)->get(route('field.collections.create', $schedule))
            ->assertOk()
            ->assertSee($schedule->party->name)
            ->assertSee('৯,৮০০.০০')
            ->assertSee('name="amount" value="300.00"', false)
            ->assertSee('data-step-start', false)
            ->assertSeeInOrder([__('app.field.member_summary'), $schedule->account->account_number, $saving->account_number, __('app.accounts.balance'), '৳১০০.০০'])
            ->assertDontSee($closed->account_number)
            ->assertSee('name="client_uuid"', false)
            ->assertSee('js-queue-collect', false)
            ->assertSee('data-schedule-id="'.$schedule->id.'"', false);
    }

    public function test_officer_records_a_collection_once_per_client_uuid(): void
    {
        $schedule = $this->schedule(Account::factory()->loan()->create(), '500');
        $payload = ['amount' => '৫০০', 'payment_method' => 'cash', 'client_uuid' => (string) Str::uuid()];

        $this->actingAs($this->officer)->post(route('field.collections.store', $schedule), $payload)
            ->assertSessionHas('status');
        $transaction = Transaction::query()->sole();
        $this->actingAs($this->officer)->post(route('field.collections.store', $schedule), $payload)
            ->assertRedirect(route('field.receipts.show', $transaction));

        $this->assertSame(1, Transaction::query()->count());
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

    public function test_earlier_unpaid_lists_own_missed_and_partial_rows_from_the_last_30_days(): void
    {
        $missed = $this->schedule(Account::factory()->create(), '100', ['due_date' => '2026-10-10', 'status' => ScheduleStatus::Missed]);
        $partial = $this->schedule(Account::factory()->create(), '100', ['due_date' => '2026-09-12', 'status' => ScheduleStatus::PartiallyPaid]);
        $tooOld = $this->schedule(Account::factory()->create(), '100', ['due_date' => '2026-09-11', 'status' => ScheduleStatus::Missed]);
        $paid = $this->schedule(Account::factory()->create(), '100', ['due_date' => '2026-10-10', 'status' => ScheduleStatus::Paid]);
        $others = CollectionSchedule::factory()->create([
            'due_date' => '2026-10-10',
            'status' => ScheduleStatus::Missed,
            'field_officer_id' => User::factory()->withRole(Role::FieldOfficer)->create()->id,
        ]);

        $this->actingAs($this->officer)->get(route('field.home'))
            ->assertOk()
            ->assertSee(__('app.field.earlier'))
            ->assertSee(route('field.collections.create', $missed))
            ->assertSee(route('field.collections.create', $partial))
            ->assertDontSee($tooOld->account->account_number)
            ->assertDontSee($paid->account->account_number)
            ->assertDontSee($others->account->account_number);

        $this->actingAs($this->officer)->get(route('field.collections.create', $missed))->assertOk();
    }

    public function test_receipt_is_visible_to_its_officer_and_office_staff_only(): void
    {
        $schedule = $this->schedule(Account::factory()->loan()->create(), '500');
        $this->collect($schedule, '500');
        $transaction = Transaction::query()->sole();
        $otherOfficer = User::factory()->withRole(Role::FieldOfficer)->create();
        $manager = User::factory()->withRole(Role::Manager)->create();

        $this->actingAs($this->officer)->get(route('field.receipts.show', $transaction))
            ->assertOk()
            ->assertSee((string) $transaction->receipt_no)
            ->assertSee($schedule->party->name)
            ->assertSee('৫০০.০০');
        $this->actingAs($otherOfficer)->get(route('field.receipts.show', $transaction))->assertForbidden();
        $this->actingAs($manager)->get(route('admin.transactions.receipt', $transaction))
            ->assertOk()
            ->assertSee((string) $transaction->receipt_no);
        $this->actingAs($manager)->get(route('admin.accounts.statement', $schedule->account))
            ->assertSee(route('admin.transactions.receipt', $transaction));
    }

    public function test_entries_without_a_receipt_number_have_no_receipt(): void
    {
        $disbursement = Transaction::factory()->create([
            'account_id' => Account::factory()->loan()->create()->id,
            'type' => TransactionType::LoanDisbursement,
            'field_officer_id' => $this->officer->id,
            'receipt_no' => null,
        ]);

        $this->actingAs($this->officer)->get(route('field.receipts.show', $disbursement))->assertForbidden();
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
