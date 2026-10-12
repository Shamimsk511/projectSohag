<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OfficeEntriesTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private User $manager;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->accountant = User::factory()->withRole(Role::Accountant)->create();
        $this->manager = User::factory()->withRole(Role::Manager)->create();
        $this->today = today(config('app.display_timezone'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postEntry(User $actor, array $data): TestResponse
    {
        return $this->actingAs($actor)->post(route('admin.office-entries.store'), $data + [
            'type' => 'office_expense',
            'amount' => '250',
            'transaction_date' => $this->today->toDateString(),
            'payment_method' => 'cash',
            'description' => 'Electricity bill',
        ]);
    }

    public function test_accountant_records_an_office_expense(): void
    {
        $this->postEntry($this->accountant, ['amount' => '১,২৫০', 'payment_method' => 'bank', 'reference' => 'TRX-9'])
            ->assertRedirect(route('admin.office-entries.index'))
            ->assertSessionHas('status', __('app.transactions.recorded'));

        $transaction = Transaction::sole();
        $this->assertSame(TransactionType::OfficeExpense, $transaction->type);
        $this->assertSame(125_000, $transaction->amount->poisha);
        $this->assertSame(PaymentMethod::Bank, $transaction->payment_method);
        $this->assertSame('TRX-9', $transaction->reference);
        $this->assertSame('Electricity bill', $transaction->description);
        $this->assertNull($transaction->account_id);
        $this->assertNull($transaction->party_id);
        $this->assertNull($transaction->receipt_no);
        $this->assertSame($this->accountant->id, $transaction->created_by);
    }

    #[TestWith(['saving_deposit'])]
    #[TestWith(['loan_collection'])]
    #[TestWith(['reversal'])]
    public function test_only_office_types_are_accepted(string $type): void
    {
        $this->postEntry($this->accountant, ['type' => $type])->assertSessionHasErrors('type');

        $this->assertSame(0, Transaction::count());
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->postEntry($this->accountant, [
            'amount' => '0', 'transaction_date' => '12/10/2026', 'payment_method' => 'cheque', 'description' => '',
        ])->assertSessionHasErrors(['amount', 'transaction_date', 'payment_method', 'description']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_service_rules_come_back_as_field_errors(): void
    {
        $this->postEntry($this->accountant, ['transaction_date' => $this->today->copy()->addDay()->toDateString()])
            ->assertSessionHasErrors(['transaction_date' => __('app.transactions.future_date')]);

        $this->assertSame(0, Transaction::count());
    }

    public function test_only_office_posters_can_record(): void
    {
        $this->postEntry($this->manager, [])->assertForbidden();
        $this->postEntry(User::factory()->withRole(Role::FieldOfficer)->create(), [])->assertForbidden();

        $this->assertSame(0, Transaction::count());
    }

    public function test_list_access_follows_permissions(): void
    {
        $index = route('admin.office-entries.index');
        $form = __('app.office_entries.new');

        $this->actingAs($this->accountant)->get($index)->assertOk()->assertSee($form);
        $this->actingAs($this->manager)->get($index)->assertOk()->assertDontSee($form);
        $this->actingAs(User::factory()->withRole(Role::FieldOfficer)->create())->get($index)->assertForbidden();
    }

    public function test_list_defaults_to_this_month_and_totals_exclude_reversed_rows(): void
    {
        Transaction::factory()->office(TransactionType::OfficeIncome, '900')->create(['description' => 'Form fees']);
        Transaction::factory()->office(TransactionType::OfficeExpense, '300')->create(['description' => 'Tea']);
        $reversed = Transaction::factory()->office(TransactionType::OfficeExpense, '50')->create(['description' => 'Typo entry']);
        Transaction::factory()->reversing($reversed)->create();
        Transaction::factory()->office(TransactionType::OfficeExpense, '70')
            ->create(['description' => 'Old rent', 'transaction_date' => $this->today->copy()->startOfMonth()->subDay()]);

        $this->actingAs($this->manager)->get(route('admin.office-entries.index'))->assertOk()
            ->assertSee('Form fees')
            ->assertSee('Typo entry')
            ->assertDontSee('Old rent')
            ->assertSee(Money::fromTaka('900')->format())
            ->assertSee(Money::fromTaka('300')->format())
            ->assertSee(Money::fromTaka('600')->format());
    }

    public function test_list_filters_by_period(): void
    {
        $lastMonth = $this->today->copy()->startOfMonth()->subDay();
        Transaction::factory()->office(TransactionType::OfficeExpense, '70')->create(['description' => 'Old rent', 'transaction_date' => $lastMonth]);
        Transaction::factory()->office(TransactionType::OfficeExpense, '80')->create(['description' => 'New rent']);

        $this->actingAs($this->accountant)->get(route('admin.office-entries.index', [
            'from' => $lastMonth->toDateString(), 'to' => $lastMonth->toDateString(),
        ]))->assertOk()->assertSee('Old rent')->assertDontSee('New rent');

        $this->actingAs($this->accountant)->get(route('admin.office-entries.index', ['from' => '2026-10-10', 'to' => '2026-10-01']))
            ->assertSessionHasErrors('to');
    }

    public function test_reverse_is_offered_to_reversers_on_unreversed_rows_only(): void
    {
        $open = Transaction::factory()->office(TransactionType::OfficeIncome, '900')->create();
        $reversed = Transaction::factory()->office(TransactionType::OfficeExpense, '50')->create();
        Transaction::factory()->reversing($reversed)->create();
        $index = route('admin.office-entries.index');

        $this->actingAs($this->manager)->get($index)->assertOk()
            ->assertSee(route('admin.transactions.reverse', $open))
            ->assertDontSee(route('admin.transactions.reverse', $reversed));

        $this->actingAs($this->accountant)->get($index)->assertOk()
            ->assertDontSee(route('admin.transactions.reverse', $open));
    }

    public function test_manager_reverses_an_office_entry_back_to_the_list(): void
    {
        $original = Transaction::factory()->office(TransactionType::OfficeExpense, '50')->create();
        $index = route('admin.office-entries.index');

        $this->actingAs($this->manager)->from($index)->post(route('admin.transactions.reverse', $original), ['reason' => 'Duplicate'])
            ->assertRedirect($index)
            ->assertSessionHas('status', __('app.transactions.reversed'));

        $this->assertSame(1, Transaction::query()->where('reverses_transaction_id', $original->id)->count());
    }

    public function test_office_totals_count_only_effective_rows_in_the_period(): void
    {
        Transaction::factory()->office(TransactionType::OfficeIncome, '900')->create();
        Transaction::factory()->office(TransactionType::OfficeIncome, '100')->create(['transaction_date' => $this->today->copy()->subMonth()]);
        Transaction::factory()->office(TransactionType::OfficeExpense, '300')->create();
        $reversed = Transaction::factory()->office(TransactionType::OfficeExpense, '50')->create();
        Transaction::factory()->reversing($reversed)->create();
        Transaction::factory()->create(); // savings deposit: not office money

        $totals = app(LedgerService::class)->officeTotals($this->today->copy()->startOfMonth(), $this->today);

        $this->assertSame(90_000, $totals['income']->poisha);
        $this->assertSame(30_000, $totals['expense']->poisha);
    }

    public function test_menu_shows_office_entries_to_viewers(): void
    {
        $this->actingAs($this->accountant)->get(route('admin.dashboard'))->assertOk()
            ->assertSee(route('admin.office-entries.index'));
    }
}
