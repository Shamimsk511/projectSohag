<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReadyToCloseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
    }

    public function test_only_active_loans_with_nothing_left_are_listed(): void
    {
        $paidOff = Account::factory()->loan('1000')->create();
        Transaction::factory()->of($paidOff, TransactionType::LoanCollection, '1000')->create(['transaction_date' => '2026-10-10']);

        $stillOwing = Account::factory()->loan('1000')->create();
        Transaction::factory()->of($stillOwing, TransactionType::LoanCollection, '999')->create();

        $reversed = Account::factory()->loan('1000')->create();
        $collection = Transaction::factory()->of($reversed, TransactionType::LoanCollection, '1000')->create();
        Transaction::factory()->reversing($collection)->create();

        $closed = Account::factory()->loan('1000')->create(['closed_at' => '2026-10-11']);
        Transaction::factory()->of($closed, TransactionType::LoanCollection, '1000')->create();

        Account::factory()->create(); // saving with zero balance is not a loan

        $lines = app(ReportService::class)->readyToClose();

        $this->assertSame([$paidOff->id], array_map(fn (array $line): int => $line['account']->id, $lines));
        $this->assertSame('2026-10-10', $lines[0]['last_paid']?->toDateString());
    }

    public function test_manager_sees_the_list_and_closes_from_the_dashboard(): void
    {
        $manager = User::factory()->withRole(Role::Manager)->create();
        $account = Account::factory()->loan('1000')->create();
        Transaction::factory()->of($account, TransactionType::LoanCollection, '1000')->create();

        $this->actingAs($manager)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(__('app.dash.ready_to_close'))
            ->assertSee($account->account_number);

        $this->actingAs($manager)
            ->from(route('admin.dashboard'))
            ->post(route('admin.accounts.close', $account))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('status', __('app.accounts.closed_message'));

        $this->assertNotNull($account->fresh()->closed_at);
        $this->actingAs($manager)->get(route('admin.dashboard'))->assertDontSee(__('app.dash.ready_to_close'));
    }

    public function test_accountant_does_not_get_the_close_list(): void
    {
        $account = Account::factory()->loan('1000')->create();
        Transaction::factory()->of($account, TransactionType::LoanCollection, '1000')->create();

        $this->actingAs(User::factory()->withRole(Role::Accountant)->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee(__('app.dash.ready_to_close'));
    }
}
