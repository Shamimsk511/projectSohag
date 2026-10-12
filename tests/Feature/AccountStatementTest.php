<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountStatementTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    }

    public function test_statement_lists_the_period_with_opening_and_closing_figures(): void
    {
        $saving = Account::factory()->create();
        Transaction::factory()->of($saving, TransactionType::SavingDeposit, '500')->create(['transaction_date' => '2026-09-30']);
        Transaction::factory()->of($saving, TransactionType::SavingDeposit, '250')->create([
            'transaction_date' => '2026-10-05', 'receipt_no' => 'R-202610-00007',
        ]);
        Transaction::factory()->of($saving, TransactionType::SavingDeposit, '900')->create([
            'transaction_date' => '2026-10-20', 'receipt_no' => 'R-202610-00099',
        ]);

        $this->actingAs($this->accountant)
            ->get(route('admin.accounts.statement', ['account' => $saving, 'from' => '2026-10-01', 'to' => '2026-10-10']))
            ->assertOk()
            ->assertSee($saving->account_number)
            ->assertSee('R-202610-00007')
            ->assertDontSee('R-202610-00099')
            ->assertSeeInOrder(['৳৫০০.০০', '৳২৫০.০০', '৳৭৫০.০০']);
    }

    public function test_statement_period_must_be_valid_dates_in_order(): void
    {
        $saving = Account::factory()->create();

        $this->actingAs($this->accountant)
            ->get(route('admin.accounts.statement', ['account' => $saving, 'from' => '2026-10-10', 'to' => '2026-10-01']))
            ->assertSessionHasErrors('to');
        $this->actingAs($this->accountant)
            ->get(route('admin.accounts.statement', ['account' => $saving, 'from' => 'yesterday']))
            ->assertSessionHasErrors('from');
        $this->actingAs($this->accountant)
            ->get(route('admin.accounts.statement', ['account' => $saving, 'to' => '2026-10-01']))
            ->assertOk();
    }

    public function test_field_officers_cannot_open_admin_statements(): void
    {
        $saving = Account::factory()->create();

        $this->actingAs(User::factory()->withRole(Role::FieldOfficer)->create())
            ->get(route('admin.accounts.statement', $saving))
            ->assertForbidden();
    }

    public function test_account_page_links_to_its_statement(): void
    {
        $saving = Account::factory()->create();

        $this->actingAs($this->accountant)->get(route('admin.accounts.show', $saving))
            ->assertOk()
            ->assertSee(route('admin.accounts.statement', $saving), false);
    }
}
