<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\TransactionService;
use App\Support\Money;
use App\Support\TransactionData;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransactionServiceTest extends TestCase
{
    use RefreshDatabase;

    private TransactionService $service;

    private User $accountant;

    private User $manager;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->service = app(TransactionService::class);
        $this->accountant = User::factory()->withRole(Role::Accountant)->create();
        $this->manager = User::factory()->withRole(Role::Manager)->create();
        $this->admin = User::factory()->withRole(Role::Admin)->create();
    }

    public function test_records_a_saving_deposit_with_party_from_account_and_a_receipt(): void
    {
        Carbon::setTestNow('2026-10-12 06:00:00');
        $saving = Account::factory()->create();

        $transaction = $this->record(TransactionType::SavingDeposit, '500', $saving, reference: 'slip 7');

        $this->assertTrue($transaction->wasRecentlyCreated);
        $this->assertSame($saving->party_id, $transaction->party_id);
        $this->assertSame($this->accountant->id, $transaction->created_by);
        $this->assertSame(PaymentMethod::Cash, $transaction->payment_method);
        $this->assertSame('slip 7', $transaction->reference);
        $this->assertSame('R-202610-00001', $transaction->receipt_no);
        $this->assertSame(50000, app(LedgerService::class)->savingBalance($saving)->poisha);
    }

    public function test_receipt_numbers_follow_the_transaction_month_and_skip_non_receipt_types(): void
    {
        Carbon::setTestNow('2026-10-12 06:00:00');
        $loan = Account::factory()->loan()->create(['opened_at' => '2026-09-01']);

        $disbursement = $this->record(TransactionType::LoanDisbursement, '1000', $loan);
        $first = $this->record(TransactionType::LoanCollection, '100', $loan);
        $september = $this->record(TransactionType::LoanCollection, '100', $loan, date: Carbon::parse('2026-09-30'));
        $second = $this->record(TransactionType::LoanCollection, '100', $loan);

        $this->assertNull($disbursement->receipt_no);
        $this->assertSame('R-202610-00001', $first->receipt_no);
        $this->assertSame('R-202609-00001', $september->receipt_no);
        $this->assertSame('R-202610-00002', $second->receipt_no);
    }

    public function test_records_office_entries_without_an_account(): void
    {
        $transaction = $this->record(TransactionType::OfficeExpense, '250', null, description: 'Rent');

        $this->assertNull($transaction->account_id);
        $this->assertNull($transaction->party_id);
        $this->assertSame('Rent', $transaction->description);
    }

    public function test_rejects_a_type_that_does_not_match_the_account(): void
    {
        $saving = Account::factory()->create();
        $loan = Account::factory()->loan()->create();

        $this->assertRejected(fn () => $this->record(TransactionType::LoanCollection, '100', $saving));
        $this->assertRejected(fn () => $this->record(TransactionType::SavingDeposit, '100', $loan));
        $this->assertRejected(fn () => $this->record(TransactionType::SavingDeposit, '100', null));
        $this->assertRejected(fn () => $this->record(TransactionType::OfficeIncome, '100', $saving));
    }

    public function test_rejects_types_without_a_defined_direction(): void
    {
        $saving = Account::factory()->create();

        $this->assertRejected(fn () => $this->record(TransactionType::Adjustment, '100', $saving));
        $this->assertRejected(fn () => $this->record(TransactionType::InvestmentDeposit, '100', null));
        $this->assertRejected(fn () => $this->record(TransactionType::Reversal, '100', $saving));
    }

    public function test_rejects_zero_amount(): void
    {
        $this->assertRejected(fn () => $this->record(TransactionType::SavingDeposit, '0', Account::factory()->create()));
    }

    public function test_rejects_future_dates_and_dates_before_the_account_opened(): void
    {
        Carbon::setTestNow('2026-10-12 06:00:00');
        $saving = Account::factory()->create(['opened_at' => '2026-10-01']);

        $this->assertRejected(fn () => $this->record(TransactionType::SavingDeposit, '100', $saving, date: Carbon::parse('2026-10-13')));
        $this->assertRejected(fn () => $this->record(TransactionType::SavingDeposit, '100', $saving, date: Carbon::parse('2026-09-30')));
    }

    public function test_rejects_entries_on_a_closed_account(): void
    {
        $this->assertRejected(fn () => $this->record(TransactionType::SavingDeposit, '100', Account::factory()->closed()->create()));
    }

    public function test_withdrawal_cannot_exceed_the_saving_balance(): void
    {
        $saving = Account::factory()->create();
        $this->record(TransactionType::SavingDeposit, '300', $saving);

        $this->assertRejected(fn () => $this->record(TransactionType::SavingWithdrawal, '300.01', $saving));

        $this->record(TransactionType::SavingWithdrawal, '300', $saving);
        $this->assertTrue(app(LedgerService::class)->savingBalance($saving)->isZero());
    }

    public function test_over_collection_is_rejected_without_override(): void
    {
        $loan = Account::factory()->loan('1000')->create();
        $this->record(TransactionType::LoanCollection, '900', $loan);

        $this->assertRejected(fn () => $this->record(TransactionType::LoanCollection, '101', $loan));
        $this->assertRejected(fn () => $this->record(TransactionType::LoanCollection, '101', $loan, overrideReason: 'paid extra'));
        $this->assertRejected(fn () => $this->record(TransactionType::LoanCollection, '101', $loan, actor: $this->admin));
    }

    public function test_admin_can_override_over_collection_with_a_reason(): void
    {
        $loan = Account::factory()->loan('1000')->create();

        $transaction = $this->record(TransactionType::LoanCollection, '1100', $loan, overrideReason: 'Party paid extra', actor: $this->admin);

        $this->assertSame('Party paid extra', $transaction->override_reason);
        $this->assertSame($this->admin->id, $transaction->approved_by);
    }

    public function test_override_reason_is_not_stored_when_no_override_was_needed(): void
    {
        $loan = Account::factory()->loan('1000')->create();

        $transaction = $this->record(TransactionType::LoanCollection, '100', $loan, overrideReason: 'not needed', actor: $this->admin);

        $this->assertNull($transaction->override_reason);
        $this->assertNull($transaction->approved_by);
    }

    public function test_duplicate_client_uuid_returns_the_existing_row(): void
    {
        $loan = Account::factory()->loan()->create();
        $officer = User::factory()->withRole(Role::FieldOfficer)->create();
        $uuid = (string) Str::uuid();

        $first = $this->record(TransactionType::LoanCollection, '100', $loan, clientUuid: $uuid, fieldOfficer: $officer, actor: $officer);
        $retry = $this->record(TransactionType::LoanCollection, '100', $loan, clientUuid: $uuid, fieldOfficer: $officer, actor: $officer);

        $this->assertFalse($retry->wasRecentlyCreated);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame($officer->id, $first->field_officer_id);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_manager_reverses_a_transaction_with_a_reason(): void
    {
        Carbon::setTestNow('2026-10-12 06:00:00');
        $loan = Account::factory()->loan('1000')->create(['opened_at' => '2026-10-01']);
        $officer = User::factory()->withRole(Role::FieldOfficer)->create();
        $original = $this->record(TransactionType::LoanCollection, '400', $loan, date: Carbon::parse('2026-10-05'), fieldOfficer: $officer);

        $reversal = $this->service->reverse($original, 'Wrong account', $this->manager);

        $this->assertSame(TransactionType::Reversal, $reversal->type);
        $this->assertSame($original->id, $reversal->reverses_transaction_id);
        $this->assertSame(40000, $reversal->amount->poisha);
        $this->assertSame($loan->id, $reversal->account_id);
        $this->assertSame($loan->party_id, $reversal->party_id);
        $this->assertSame($officer->id, $reversal->field_officer_id);
        $this->assertSame('Wrong account', $reversal->description);
        $this->assertSame('2026-10-12', $reversal->transaction_date->toDateString());
        $this->assertNull($reversal->receipt_no);
        $this->assertSame(100000, app(LedgerService::class)->loanOutstanding($loan)->poisha);
    }

    public function test_reversal_needs_permission_and_a_reason(): void
    {
        $original = $this->record(TransactionType::SavingDeposit, '100', Account::factory()->create());

        $this->assertRejected(fn () => $this->service->reverse($original, 'mistake', $this->accountant));
        $this->assertRejected(fn () => $this->service->reverse($original, '  ', $this->manager));
    }

    public function test_cannot_reverse_twice_or_reverse_a_reversal(): void
    {
        $original = $this->record(TransactionType::SavingDeposit, '100', Account::factory()->create());
        $reversal = $this->service->reverse($original, 'mistake', $this->manager);

        $this->assertRejected(fn () => $this->service->reverse($original->fresh() ?? $original, 'again', $this->manager));
        $this->assertRejected(fn () => $this->service->reverse($reversal, 'undo', $this->manager));
    }

    public function test_cannot_reverse_on_a_closed_account(): void
    {
        $saving = Account::factory()->create();
        $original = $this->record(TransactionType::SavingDeposit, '100', $saving);
        $saving->update(['closed_at' => today(config('app.display_timezone'))]);

        $this->assertRejected(fn () => $this->service->reverse($original, 'mistake', $this->manager));
    }

    public function test_cannot_reverse_a_deposit_that_was_already_withdrawn(): void
    {
        $saving = Account::factory()->create();
        $deposit = $this->record(TransactionType::SavingDeposit, '100', $saving);
        $withdrawal = $this->record(TransactionType::SavingWithdrawal, '60', $saving);

        $this->assertRejected(fn () => $this->service->reverse($deposit, 'mistake', $this->manager));

        $this->service->reverse($withdrawal, 'mistake', $this->manager);
        $this->service->reverse($deposit, 'mistake', $this->manager);
        $this->assertTrue(app(LedgerService::class)->savingBalance($saving)->isZero());
    }

    private function record(
        TransactionType $type,
        string $taka,
        ?Account $account,
        ?Carbon $date = null,
        ?string $reference = null,
        ?string $description = null,
        ?string $clientUuid = null,
        ?User $fieldOfficer = null,
        ?string $overrideReason = null,
        ?User $actor = null,
    ): Transaction {
        return $this->service->record(new TransactionData(
            type: $type,
            amount: Money::fromTaka($taka),
            date: $date ?? today(config('app.display_timezone')),
            account: $account,
            reference: $reference,
            description: $description,
            clientUuid: $clientUuid,
            fieldOfficer: $fieldOfficer,
            overrideReason: $overrideReason,
        ), $actor ?? $this->accountant);
    }

    /**
     * @param  callable(): mixed  $write
     */
    private function assertRejected(callable $write): void
    {
        $before = Transaction::query()->count();

        try {
            $write();
            $this->fail('Expected the write to be rejected.');
        } catch (ValidationException) {
            $this->assertSame($before, Transaction::query()->count());
        }
    }
}
