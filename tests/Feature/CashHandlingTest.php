<?php

namespace Tests\Feature;

use App\Enums\CashHandoverMode;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CashHandover;
use App\Models\DailyClosing;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashService;
use App\Services\LedgerService;
use App\Services\SettingService;
use App\Services\TransactionService;
use App\Support\Money;
use App\Support\TransactionData;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CashHandlingTest extends TestCase
{
    use RefreshDatabase;

    private CashService $cash;

    private User $officer;

    private User $accountant;

    private User $admin;

    private Account $saving;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 06:00:00');
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->cash = app(CashService::class);
        $this->officer = User::factory()->withRole(Role::FieldOfficer)->create();
        $this->accountant = User::factory()->withRole(Role::Accountant)->create();
        $this->admin = User::factory()->withRole(Role::Admin)->create();
        $this->saving = Account::factory()->create(['opened_at' => '2026-09-01']);
        $this->today = today(config('app.display_timezone'));
    }

    private function collect(string $taka, PaymentMethod $method = PaymentMethod::Cash, ?Carbon $date = null, ?User $actor = null): Transaction
    {
        return app(TransactionService::class)->record(new TransactionData(
            type: TransactionType::SavingDeposit,
            amount: Money::fromTaka($taka),
            date: $date ?? $this->today,
            account: $this->saving,
            paymentMethod: $method,
            fieldOfficer: $this->officer,
        ), $actor ?? $this->officer);
    }

    private function cashInHand(): int
    {
        return app(LedgerService::class)->cashInHand([$this->officer->id])[$this->officer->id]->poisha;
    }

    private function assertRejected(string $field, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a validation error on '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_cash_in_hand_counts_effective_cash_collections_minus_handovers(): void
    {
        $this->collect('500');
        $this->collect('300', PaymentMethod::MobileBanking);
        $reversed = $this->collect('200');
        app(TransactionService::class)->reverse($reversed, 'Wrong member', User::factory()->withRole(Role::Manager)->create());

        $this->assertSame(50_000, $this->cashInHand());

        $handover = $this->cash->recordHandover($this->officer, Money::fromTaka('400'), $this->today, 'Evening cash', $this->accountant);

        $this->assertSame(10_000, $this->cashInHand());
        $this->assertSame($this->accountant->id, $handover->confirmed_by);
        $this->assertSame('Evening cash', $handover->note);
        $this->assertSame(1, AuditLog::query()->where('action', 'cash.handed_over')->count());
    }

    public function test_handover_cannot_exceed_cash_in_hand_or_be_zero(): void
    {
        $this->collect('500');

        $this->assertRejected('amount', fn () => $this->cash->recordHandover($this->officer, Money::fromTaka('500.01'), $this->today, null, $this->accountant));
        $this->assertRejected('amount', fn () => $this->cash->recordHandover($this->officer, Money::zero(), $this->today, null, $this->accountant));
        $this->assertSame(0, CashHandover::count());
    }

    public function test_handover_needs_a_field_officer_and_a_date_not_in_the_future(): void
    {
        $this->collect('500');

        $this->assertRejected('field_officer_id', fn () => $this->cash->recordHandover($this->accountant, Money::fromTaka('100'), $this->today, null, $this->accountant));
        $this->assertRejected('handover_date', fn () => $this->cash->recordHandover($this->officer, Money::fromTaka('100'), $this->today->copy()->addDay(), null, $this->accountant));
    }

    public function test_closing_a_day_locks_it_for_non_admins(): void
    {
        $this->collect('500');
        $closing = $this->cash->closeDay($this->today, $this->accountant);

        $this->assertSame($this->accountant->id, $closing->closed_by);
        $this->assertTrue(DailyClosing::isClosed($this->today));
        $this->assertSame(1, AuditLog::query()->where('action', 'day.closed')->count());

        $this->assertRejected('transaction_date', fn () => $this->collect('100'));
        $this->assertRejected('handover_date', fn () => $this->cash->recordHandover($this->officer, Money::fromTaka('100'), $this->today, null, $this->accountant));

        $this->collect('100', actor: $this->admin);
        $this->cash->recordHandover($this->officer, Money::fromTaka('100'), $this->today, null, $this->admin);
        $this->assertSame(50_000, $this->cashInHand());
    }

    public function test_rows_on_a_closed_day_cannot_be_reversed_by_non_admins(): void
    {
        $yesterday = $this->today->copy()->subDay();
        $earlier = $this->collect('100', date: $yesterday);
        $this->cash->closeDay($yesterday, $this->accountant);
        $manager = User::factory()->withRole(Role::Manager)->create();

        $this->assertRejected('reason', fn () => app(TransactionService::class)->reverse($earlier, 'Wrong member', $manager));

        $todays = $this->collect('100');
        $this->cash->closeDay($this->today, $this->accountant);
        $this->assertRejected('reason', fn () => app(TransactionService::class)->reverse($todays, 'Wrong member', $manager));

        app(TransactionService::class)->reverse($earlier, 'Wrong member', $this->admin);
        $this->assertSame(1, Transaction::query()->where('type', TransactionType::Reversal)->count());
    }

    public function test_a_day_is_closed_once_and_never_in_advance(): void
    {
        $this->cash->closeDay($this->today, $this->accountant);

        $this->assertRejected('date', fn () => $this->cash->closeDay($this->today, $this->admin));
        $this->assertRejected('date', fn () => $this->cash->closeDay($this->today->copy()->addDay(), $this->admin));
        $this->assertSame(1, DailyClosing::count());
    }

    public function test_accountant_records_a_handover_from_the_cash_page(): void
    {
        $this->collect('500');

        $this->actingAs($this->accountant)->get(route('admin.cash-handovers.index'))
            ->assertOk()
            ->assertSee($this->officer->name)
            ->assertSee(Money::fromTaka('500')->format());

        $this->actingAs($this->accountant)->post(route('admin.cash-handovers.store'), [
            'field_officer_id' => $this->officer->id,
            'amount' => '৪০০',
            'handover_date' => $this->today->toDateString(),
            'note' => 'Counted twice',
        ])->assertRedirect(route('admin.cash-handovers.index'))->assertSessionHas('status');

        $this->assertSame(40_000, CashHandover::sole()->amount->poisha);
    }

    public function test_field_officer_cannot_open_cash_or_closing_pages(): void
    {
        $this->actingAs($this->officer)->get(route('admin.cash-handovers.index'))->assertForbidden();
        $this->actingAs($this->officer)->get(route('admin.daily-closings.index'))->assertForbidden();
    }

    public function test_manager_closes_a_day_from_the_closing_page(): void
    {
        $manager = User::factory()->withRole(Role::Manager)->create();

        $this->actingAs($manager)->post(route('admin.daily-closings.store'), ['date' => $this->today->toDateString()])
            ->assertRedirect(route('admin.daily-closings.index'))
            ->assertSessionHas('status');

        $this->actingAs($manager)->get(route('admin.daily-closings.index'))
            ->assertOk()
            ->assertSee($manager->name);
    }

    public function test_field_home_shows_cash_in_hand_and_the_closed_banner(): void
    {
        $this->collect('500');

        $this->actingAs($this->officer)->get(route('field.home'))
            ->assertOk()
            ->assertSee(Money::fromTaka('500')->format())
            ->assertDontSee(__('app.closings.today_closed'));

        DailyClosing::factory()->create(['date' => $this->today->toDateString()]);

        $this->actingAs($this->officer)->get(route('field.home'))->assertSee(__('app.closings.today_closed'));
    }

    public function test_automatic_mode_hands_over_each_cash_collection_at_once(): void
    {
        app(SettingService::class)->setCashHandoverMode(CashHandoverMode::Automatic, $this->admin);

        $collection = $this->collect('500');
        $this->collect('300', PaymentMethod::MobileBanking);

        $handover = CashHandover::query()->sole();
        $this->assertSame($collection->id, $handover->transaction_id);
        $this->assertSame(50_000, $handover->amount->poisha);
        $this->assertSame($this->officer->id, $handover->confirmed_by);
        $this->assertSame(0, $this->cashInHand());
        $this->assertSame(50_000, app(LedgerService::class)->officeCashBalance(null)->poisha);
        $this->assertSame(1, AuditLog::query()->where('action', 'cash.handed_over')->count());
    }

    public function test_reversing_an_automatically_handed_over_collection_leaves_no_cash_anywhere(): void
    {
        app(SettingService::class)->setCashHandoverMode(CashHandoverMode::Automatic, $this->admin);
        $collection = $this->collect('500');

        app(TransactionService::class)->reverse($collection, 'Wrong member', User::factory()->withRole(Role::Manager)->create());

        $this->assertSame(0, $this->cashInHand());
        $this->assertSame(0, app(LedgerService::class)->officeCashBalance(null)->poisha);
        $this->assertSame(1, CashHandover::count());
    }

    public function test_switching_mode_never_changes_earlier_figures(): void
    {
        $this->collect('200');
        app(SettingService::class)->setCashHandoverMode(CashHandoverMode::Automatic, $this->admin);
        $this->collect('500');

        $this->assertSame(20_000, $this->cashInHand());

        app(SettingService::class)->setCashHandoverMode(CashHandoverMode::Manual, $this->admin);
        $this->collect('100');

        $this->assertSame(30_000, $this->cashInHand());
        $this->assertSame(1, CashHandover::count());
    }

    public function test_repeated_client_uuid_does_not_hand_over_twice(): void
    {
        app(SettingService::class)->setCashHandoverMode(CashHandoverMode::Automatic, $this->admin);
        $data = new TransactionData(
            type: TransactionType::SavingDeposit,
            amount: Money::fromTaka('500'),
            date: $this->today,
            account: $this->saving,
            clientUuid: '0b9a7c1e-2f61-4d4e-9a55-0d0c8f6c1a01',
            fieldOfficer: $this->officer,
        );

        app(TransactionService::class)->record($data, $this->officer);
        app(TransactionService::class)->record($data, $this->officer);

        $this->assertSame(1, CashHandover::count());
    }
}
