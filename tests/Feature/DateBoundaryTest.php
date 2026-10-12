<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\ScheduleStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\CollectionPlan;
use App\Models\CollectionSchedule;
use App\Models\DailyClosing;
use App\Models\Holiday;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\TransactionService;
use App\Support\Money;
use App\Support\TransactionData;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Business dates are Dhaka dates (UTC+6) while the app clock and date casts are UTC. Each test pins
 * the clock just before or after Dhaka midnight (18:00 UTC) to catch UTC "today" leaking in.
 */
class DateBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /** 23:59:59 on 12 Oct in Dhaka. */
    private const DHAKA_LAST_SECOND = '2026-10-12 17:59:59';

    /** 00:30 on 13 Oct in Dhaka, still 12 Oct in UTC. */
    private const DHAKA_AFTER_MIDNIGHT = '2026-10-12 18:30:00';

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow(self::DHAKA_LAST_SECOND);
        $this->officer = User::factory()->withRole(Role::FieldOfficer)->create();
    }

    public function test_day_list_and_collection_date_follow_the_dhaka_day(): void
    {
        $twelfth = $this->schedule('2026-10-12');
        $thirteenth = $this->schedule('2026-10-13');

        $this->actingAs($this->officer)->get(route('field.home'))
            ->assertSee(route('field.collections.create', $twelfth))
            ->assertDontSee(route('field.collections.create', $thirteenth));

        Carbon::setTestNow(self::DHAKA_AFTER_MIDNIGHT);
        $this->actingAs($this->officer)->get(route('field.home'))
            ->assertSee(route('field.collections.create', $thirteenth))
            ->assertDontSee(route('field.collections.create', $twelfth));

        $this->actingAs($this->officer)->post(route('field.collections.store', $thirteenth), [
            'amount' => '100', 'payment_method' => 'cash', 'client_uuid' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-13', Transaction::query()->sole()->transaction_date->toDateString());
    }

    public function test_receipt_numbers_switch_month_at_dhaka_midnight(): void
    {
        Carbon::setTestNow('2026-10-31 17:59:00'); // 23:59 on 31 Oct in Dhaka
        $account = Account::factory()->create(['opened_at' => '2026-10-01']);
        $october = $this->deposit($account);

        Carbon::setTestNow('2026-10-31 18:01:00'); // 00:01 on 1 Nov in Dhaka
        $november = $this->deposit($account);

        $this->assertSame(['2026-10-31', 'R-202610-00001'], [$october->transaction_date->toDateString(), $october->receipt_no]);
        $this->assertSame(['2026-11-01', 'R-202611-00001'], [$november->transaction_date->toDateString(), $november->receipt_no]);
    }

    public function test_closed_day_blocks_posting_until_its_last_dhaka_second_only(): void
    {
        DailyClosing::factory()->create(['date' => '2026-10-12']);
        $twelfth = $this->schedule('2026-10-12');
        $thirteenth = $this->schedule('2026-10-13');

        $this->actingAs($this->officer)->post(route('field.collections.store', $twelfth), [
            'amount' => '100', 'payment_method' => 'cash', 'client_uuid' => (string) Str::uuid(),
        ])->assertSessionHasErrors('transaction_date');

        Carbon::setTestNow(self::DHAKA_AFTER_MIDNIGHT);
        $this->actingAs($this->officer)->post(route('field.collections.store', $thirteenth), [
            'amount' => '100', 'payment_method' => 'cash', 'client_uuid' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_sync_window_is_the_dhaka_yesterday_and_today(): void
    {
        Carbon::setTestNow(self::DHAKA_AFTER_MIDNIGHT);
        $schedule = $this->schedule('2026-10-12', ['status' => ScheduleStatus::Missed]);
        $schedule->account->update(['opened_at' => '2026-10-01']);
        $item = fn (string $collectedAt): array => [
            'client_uuid' => (string) Str::uuid(), 'schedule_id' => $schedule->id, 'amount' => '10',
            'payment_method' => 'cash', 'description' => null, 'collected_at' => $collectedAt,
        ];

        $results = $this->actingAs($this->officer)->postJson(route('field.api.collections.sync'), ['items' => [
            $item('2026-10-12T23:50:00+06:00'),
            $item('2026-10-11T23:59:00+06:00'),
            $item('2026-10-11T18:10:00Z'), // 00:10 on the 12th in Dhaka
        ]])->assertOk()->json('results');

        $this->assertSame(['created', 'rejected', 'created'], array_column($results, 'status'));
        $this->assertSame(['2026-10-12', '2026-10-12'], Transaction::query()->orderBy('id')->get()->map(
            fn (Transaction $row): string => $row->transaction_date->toDateString(),
        )->all());
    }

    public function test_nightly_commands_use_the_dhaka_calendar(): void
    {
        $ended = $this->schedule('2026-10-12');
        $started = $this->schedule('2026-10-13');

        Carbon::setTestNow('2026-10-12 18:17:00'); // 00:17 on 13 Oct in Dhaka, when mark-missed runs
        $this->artisan('schedules:mark-missed')->assertSuccessful();
        $this->assertSame(ScheduleStatus::Missed, $ended->fresh()?->status);
        $this->assertSame(ScheduleStatus::Pending, $started->fresh()?->status);

        $plan = CollectionPlan::factory()->create(['starts_on' => '2026-10-13']);
        Carbon::setTestNow('2026-10-13 12:07:00'); // 18:07 on 13 Oct in Dhaka, when generate runs
        $this->artisan('schedules:generate')->assertSuccessful();
        $this->assertSame(['2026-10-14'], CollectionSchedule::query()->where('account_id', $plan->account_id)->get()
            ->map(fn (CollectionSchedule $row): string => $row->due_date->toDateString())->all());

        $events = collect(app(Schedule::class)->events())->mapWithKeys(fn (Event $event): array => [
            (string) Str::of((string) $event->command)->afterLast(' ') => [$event->expression, $event->timezone],
        ]);
        $this->assertSame(['7 18 * * *', 'Asia/Dhaka'], $events['schedules:generate']);
        $this->assertSame(['17 0 * * *', 'Asia/Dhaka'], $events['schedules:mark-missed']);
        $this->assertSame(['27 3 * * *', 'Asia/Dhaka'], $events['photos:prune-temp']);
    }

    public function test_office_entries_default_to_the_dhaka_month(): void
    {
        $admin = User::factory()->withRole(Role::Admin)->create();
        $entry = fn (string $date, string $description): Transaction => Transaction::factory()->create([
            'account_id' => null, 'type' => TransactionType::OfficeIncome, 'transaction_date' => $date, 'description' => $description,
        ]);
        $entry('2026-10-31', 'Last October entry');
        $entry('2026-11-01', 'First November entry');

        Carbon::setTestNow('2026-10-31 18:30:00'); // 00:30 on 1 Nov in Dhaka
        $this->actingAs($admin)->get(route('admin.office-entries.index'))
            ->assertSee('First November entry')
            ->assertDontSee('Last October entry');

        $this->actingAs($admin)->get(route('admin.office-entries.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertSee('Last October entry')
            ->assertDontSee('First November entry');
    }

    public function test_holidays_on_the_dhaka_today_can_be_added_but_not_removed(): void
    {
        $service = app(CollectionService::class);
        $manager = User::factory()->withRole(Role::Manager)->create();

        Carbon::setTestNow(self::DHAKA_AFTER_MIDNIGHT); // 13 Oct in Dhaka, 12 Oct in UTC
        $today = $service->addHoliday(Carbon::parse('2026-10-13'), 'Today', $manager);
        $this->assertThrows(fn () => $service->addHoliday(Carbon::parse('2026-10-12'), 'Yesterday', $manager), ValidationException::class);
        $this->assertThrows(fn () => $service->removeHoliday($today, $manager), ValidationException::class);

        $service->removeHoliday(Holiday::factory()->create(['date' => '2026-10-14']), $manager);
        $this->assertModelExists($today);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function schedule(string $dueDate, array $attributes = []): CollectionSchedule
    {
        return CollectionSchedule::factory()->create([
            'account_id' => Account::factory()->create(['opened_at' => '2026-10-01'])->id,
            'field_officer_id' => $this->officer->id,
            'due_date' => $dueDate,
            'expected_amount' => Money::fromTaka('100'),
            ...$attributes,
        ]);
    }

    private function deposit(Account $account): Transaction
    {
        return app(TransactionService::class)->record(new TransactionData(
            type: TransactionType::SavingDeposit,
            amount: Money::fromTaka('10'),
            date: today(config('app.display_timezone')),
            account: $account,
        ), $this->officer);
    }
}
