<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\ActiveStatus;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\Area;
use App\Models\AuditLog;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AssignmentService;
use App\Services\TransactionService;
use App\Services\UserService;
use App\Support\Money;
use App\Support\TransactionData;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->accountant = User::factory()->withRole(Role::Accountant)->create();
        $this->manager = User::factory()->withRole(Role::Manager)->create();
    }

    public function test_recording_a_transaction_logs_its_raw_values_and_actor(): void
    {
        $saving = Account::factory()->create();

        $transaction = $this->deposit($saving, '500', 'uuid-1');

        $log = $this->onlyLog('transaction.recorded');
        $this->assertSame($this->accountant->id, $log->user_id);
        $this->assertTrue($log->auditable->is($transaction));
        $this->assertNull($log->before);
        $this->assertSame(50000, $log->after['amount']);
        $this->assertSame('saving_deposit', $log->after['type']);
        $this->assertSame('R-'.now(config('app.display_timezone'))->format('Ym').'-00001', $log->after['receipt_no']);
    }

    public function test_rejected_and_duplicate_writes_log_nothing(): void
    {
        $saving = Account::factory()->create();
        $this->deposit($saving, '500', 'uuid-1');

        $this->deposit($saving, '500', 'uuid-1');
        try {
            app(TransactionService::class)->record(new TransactionData(
                type: TransactionType::SavingWithdrawal,
                amount: Money::fromTaka('900'),
                date: today(config('app.display_timezone')),
                account: $saving,
            ), $this->accountant);
            $this->fail('Expected the withdrawal to be rejected.');
        } catch (ValidationException) {
        }

        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_reversal_is_logged_against_the_reversal_row(): void
    {
        $original = $this->deposit(Account::factory()->create(), '500');

        $reversal = app(TransactionService::class)->reverse($original, 'Wrong account', $this->manager);

        $log = $this->onlyLog('transaction.reversed');
        $this->assertSame($this->manager->id, $log->user_id);
        $this->assertTrue($log->auditable->is($reversal));
        $this->assertSame($original->id, $log->after['reverses_transaction_id']);
        $this->assertSame('Wrong account', $log->after['description']);
    }

    public function test_account_open_adjust_and_close_are_logged_with_changed_values(): void
    {
        $accounts = app(AccountService::class);
        $party = Party::factory()->create();

        $loan = $accounts->open($party, AccountType::Loan, Money::fromTaka('10000'), today(config('app.display_timezone')), $this->manager);
        $accounts->adjustContractAmount($loan, Money::fromTaka('12000'), 'Renegotiated', $this->manager);
        $accounts->close($loan->refresh(), 'Written off', User::factory()->withRole(Role::Admin)->create());

        $this->assertSame($loan->account_number, $this->onlyLog('account.opened')->after['account_number']);

        $adjusted = $this->onlyLog('account.contract_adjusted');
        $this->assertSame(['contract_amount' => 1000000], $adjusted->before);
        $this->assertSame(['contract_amount' => 1200000, 'reason' => 'Renegotiated'], $adjusted->after);

        $closed = $this->onlyLog('account.closed');
        $this->assertSame(['closed_at' => null, 'closed_by' => null, 'close_reason' => null], $closed->before);
        $this->assertSame('Written off', $closed->after['close_reason']);
    }

    public function test_reassignment_logs_the_ended_and_the_new_assignment(): void
    {
        $party = Party::factory()->create();
        $first = User::factory()->withRole(Role::FieldOfficer)->create();
        $second = User::factory()->withRole(Role::FieldOfficer)->create();
        $assignments = app(AssignmentService::class);
        $assignments->assign($party, $first, $this->manager);

        $assignments->assign($party, $second, $this->manager);
        $assignments->assign($party, $second, $this->manager);

        $this->assertSame(2, AuditLog::query()->where('action', 'assignment.assigned')->count());
        $ended = $this->onlyLog('assignment.ended');
        $this->assertSame(['assigned_until' => null, 'ended_by' => null], $ended->before);
        $this->assertSame($this->manager->id, $ended->after['ended_by']);
    }

    public function test_user_changes_log_the_role_and_redact_the_password(): void
    {
        $users = app(UserService::class);
        $admin = User::factory()->withRole(Role::Admin)->create();

        $user = $users->create(['name' => 'Rahim', 'email' => 'rahim@example.com', 'password' => 'secret-pass-123', 'status' => UserStatus::Active->value], Role::FieldOfficer, $admin);
        $users->update($user, ['name' => 'Rahim', 'password' => 'brand-new-pass-1', 'status' => UserStatus::Inactive->value], Role::Manager, $admin);

        $created = $this->onlyLog('user.created');
        $this->assertSame('field_officer', $created->after['role']);
        $this->assertSame('[redacted]', $created->after['password']);

        $updated = $this->onlyLog('user.updated');
        $this->assertSame(['password' => '[redacted]', 'status' => 'active', 'role' => 'field_officer'], $updated->before);
        $this->assertSame(['password' => '[redacted]', 'status' => 'inactive', 'role' => 'manager'], $updated->after);
    }

    public function test_console_admin_creation_is_logged_without_an_actor(): void
    {
        $this->artisan('app:create-admin')
            ->expectsQuestion('Name', 'Boss')
            ->expectsQuestion('Email', 'boss@example.com')
            ->expectsQuestion('Password', 'secret-pass-123')
            ->assertSuccessful();

        $this->assertNull($this->onlyLog('user.created')->user_id);
    }

    public function test_party_update_over_http_logs_only_changed_columns_with_ip_and_device(): void
    {
        $party = Party::factory()->create(['name' => 'Karim Mia', 'phone' => '01711223344', 'address' => 'House 5']);

        $this->actingAs($this->manager)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('User-Agent', 'FieldPhone/1.0')
            ->put(route('admin.parties.update', $party), [
                'name' => 'Karim Uddin',
                'phone' => '01711223344',
                'address' => 'House 5',
                'area_id' => $party->area_id,
                'status' => ActiveStatus::Active->value,
            ])
            ->assertRedirect(route('admin.parties.show', $party));

        $log = $this->onlyLog('party.updated');
        $this->assertSame(['name' => 'Karim Mia'], $log->before);
        $this->assertSame(['name' => 'Karim Uddin'], $log->after);
        $this->assertSame('203.0.113.9', $log->ip);
        $this->assertSame('FieldPhone/1.0', $log->user_agent);
        $this->assertSame($this->manager->id, $log->user_id);
    }

    public function test_party_creation_logs_the_final_code(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.parties.store'), [
                'name' => 'Karim Mia',
                'phone' => '01711223344',
                'address' => 'House 5',
                'area_id' => Area::factory()->create()->id,
                'status' => ActiveStatus::Active->value,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('P-00001', $this->onlyLog('party.created')->after['party_code']);
        $this->assertSame(0, AuditLog::query()->where('action', 'party.updated')->count());
    }

    public function test_area_changes_are_logged_and_unchanged_saves_are_not(): void
    {
        $this->actingAs($this->manager)
            ->post(route('admin.areas.store'), ['name' => 'Uttara', 'status' => ActiveStatus::Active->value])
            ->assertRedirect(route('admin.areas.index'));
        $area = Area::query()->where('name', 'Uttara')->sole();

        $this->actingAs($this->manager)
            ->put(route('admin.areas.update', $area), ['name' => 'Uttara', 'status' => ActiveStatus::Inactive->value])
            ->assertRedirect(route('admin.areas.index'));
        $this->actingAs($this->manager)
            ->put(route('admin.areas.update', $area), ['name' => 'Uttara', 'status' => ActiveStatus::Inactive->value])
            ->assertRedirect(route('admin.areas.index'));

        $this->assertSame('Uttara', $this->onlyLog('area.created')->after['name']);
        $this->assertSame(['status' => 'inactive'], $this->onlyLog('area.updated')->after);
    }

    public function test_audit_logs_cannot_be_edited_or_deleted(): void
    {
        $this->deposit(Account::factory()->create(), '500');
        $log = AuditLog::query()->sole();

        $this->assertThrows(fn () => $log->update(['action' => 'x']), LogicException::class);
        $this->assertThrows(fn () => $log->delete(), LogicException::class);
        $this->assertSame('transaction.recorded', $log->refresh()->action);
    }

    private function deposit(Account $saving, string $taka, ?string $clientUuid = null): Transaction
    {
        return app(TransactionService::class)->record(new TransactionData(
            type: TransactionType::SavingDeposit,
            amount: Money::fromTaka($taka),
            date: today(config('app.display_timezone')),
            account: $saving,
            clientUuid: $clientUuid,
        ), $this->accountant);
    }

    private function onlyLog(string $action): AuditLog
    {
        return AuditLog::query()->where('action', $action)->sole();
    }
}
