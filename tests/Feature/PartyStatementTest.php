<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartyStatementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_statement_stacks_every_account_of_the_party_with_ledger_figures(): void
    {
        $party = Party::factory()->create();
        $saving = Account::factory()->for($party)->create();
        $loan = Account::factory()->loan('10000')->for($party)->create();
        $closed = Account::factory()->for($party)->closed()->create();
        $other = Account::factory()->create();
        Transaction::factory()->of($saving, TransactionType::SavingDeposit, '800')->create(['transaction_date' => '2026-10-01']);
        Transaction::factory()->of($saving, TransactionType::SavingWithdrawal, '300')->create(['transaction_date' => '2026-10-05']);
        Transaction::factory()->of($loan, TransactionType::LoanCollection, '2500')->create(['transaction_date' => '2026-10-02']);
        Transaction::factory()->of($other, TransactionType::SavingDeposit, '999')->create();

        $this->actingAs(User::factory()->withRole(Role::Accountant)->create())
            ->get(route('admin.parties.statement', $party))
            ->assertOk()
            ->assertSee($saving->account_number)
            ->assertSee($loan->account_number)
            ->assertSee($closed->account_number)
            ->assertDontSee($other->account_number)
            ->assertSee('৫০০.০০') // saving balance
            ->assertSee('৭,৫০০.০০') // loan outstanding
            ->assertDontSee(__('app.transactions.reverse'));

        $this->get(route('admin.parties.statement', ['party' => $party, 'from' => '2026-10-03']))
            ->assertOk()
            ->assertSee('৩০০.০০') // withdrawal inside the period
            ->assertDontSee('০১/১০/২০২৬'); // deposit dated before the period is folded into the opening figure
    }

    public function test_party_without_accounts_and_bad_period(): void
    {
        $party = Party::factory()->create();

        $this->actingAs(User::factory()->withRole(Role::Manager)->create())
            ->get(route('admin.parties.statement', $party))
            ->assertOk()->assertSee(__('app.parties.statement_no_accounts'));
        $this->get(route('admin.parties.statement', ['party' => $party, 'from' => '2026-10-10', 'to' => '2026-10-01']))
            ->assertSessionHasErrors('to');
    }
}
