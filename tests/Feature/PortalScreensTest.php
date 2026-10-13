<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PortalAccessService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PortalScreensTest extends TestCase
{
    use RefreshDatabase;

    private Account $loan;

    private Account $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
        $party = Party::factory()->create();
        $this->loan = Account::factory()->loan('1200')->create(['party_id' => $party->id]);
        Transaction::factory()->of($this->loan, TransactionType::LoanDisbursement, '1000')->create();
        Transaction::factory()->of($this->loan, TransactionType::LoanCollection, '200')->create(['receipt_no' => 'R-202610-00001']);
        $this->foreign = Account::factory()->loan('500')->create();

        $portal = app(PortalAccessService::class);
        ['access' => $access, 'pin' => $pin] = $portal->issue($party, User::factory()->withRole(Role::Manager)->create());
        $this->get(route('portal.link', decrypt($access->token_encrypted)));
        $this->post(route('portal.pin.check'), ['pin' => $pin]);
        $this->post(route('portal.pin.update'), ['pin' => '4321', 'pin_confirmation' => '4321']);
    }

    public function test_home_shows_own_accounts_with_remaining(): void
    {
        $this->get(route('portal.home'))->assertOk()
            ->assertSee($this->loan->account_number)
            ->assertSee(Money::fromTaka('1000')->format())
            ->assertDontSee($this->foreign->account_number)
            ->assertDontSee(route('admin.accounts.show', $this->loan), false);
    }

    public function test_statement_and_receipt_are_scoped_to_the_member(): void
    {
        $receipt = Transaction::query()->where('receipt_no', 'R-202610-00001')->sole();

        $this->get(route('portal.accounts.show', $this->loan))->assertOk()
            ->assertSee('R-202610-00001')
            ->assertSee(route('portal.receipts.show', $receipt), false)
            ->assertDontSee('admin/transactions', false);
        $this->get(route('portal.receipts.show', $receipt))->assertOk()->assertSee(Money::fromTaka('200')->format());

        $this->get(route('portal.accounts.show', $this->foreign))->assertNotFound();
        $foreignReceipt = Transaction::factory()->of($this->foreign, TransactionType::LoanCollection, '50')->create(['receipt_no' => 'R-202610-00002']);
        $this->get(route('portal.receipts.show', $foreignReceipt))->assertNotFound();
    }

    public function test_statement_period_filter_validates(): void
    {
        $this->get(route('portal.accounts.show', [$this->loan, 'from' => '2026-10-12', 'to' => '2026-10-01']))->assertSessionHasErrors('to');
    }

    public function test_members_do_not_see_staff_notes_on_the_statement(): void
    {
        Transaction::factory()->of($this->loan, TransactionType::LoanCollection, '10')
            ->create(['description' => 'internal note']);

        $this->get(route('portal.accounts.show', $this->loan))->assertOk()->assertDontSee('internal note');
        $this->actingAs(User::factory()->withRole(Role::Manager)->create())
            ->get(route('admin.accounts.statement', $this->loan))->assertOk()->assertSee('internal note');
    }
}
