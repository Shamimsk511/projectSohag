<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Account;
use App\Models\CashHandover;
use App\Models\CollectionSchedule;
use App\Models\DailyClosing;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * N+1 guard: list pages must issue the same number of queries for 2 rows and for 8.
 * (Lazy loading also throws outside production, see AppServiceProvider.)
 */
class QueryCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-10-12 06:00:00');
    }

    public function test_list_pages_do_not_grow_queries_with_row_count(): void
    {
        $admin = User::factory()->withRole(Role::Admin)->create();
        $officer = User::factory()->withRole(Role::FieldOfficer)->create();
        $account = Account::factory()->create();

        $pages = [
            'schedules' => fn () => route('admin.schedules.index', ['date' => '2026-10-12']),
            'cash' => fn () => route('admin.cash-handovers.index'),
            'closings' => fn () => route('admin.daily-closings.index'),
            'office' => fn () => route('admin.office-entries.index'),
            'statement' => fn () => route('admin.accounts.statement', $account),
            'account' => fn () => route('admin.accounts.show', $account),
            'users' => fn () => route('admin.users.index'),
            'dashboard' => fn () => route('admin.dashboard', ['date' => '2026-10-12']),
        ];

        $this->seedRows(2, $officer, $account);
        $this->countQueries($admin, $pages); // warm permission/menu caches
        $before = $this->countQueries($admin, $pages);

        $this->seedRows(6, $officer, $account);
        $after = $this->countQueries($admin, $pages);

        $this->assertSame($before, $after);
    }

    /**
     * @param  array<string, callable(): string>  $pages
     * @return array<string, int>
     */
    private function countQueries(User $admin, array $pages): array
    {
        $counts = [];

        foreach ($pages as $name => $url) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->get($url())->assertOk();
            $counts[$name] = count(DB::getQueryLog());
        }

        return $counts;
    }

    private function seedRows(int $n, User $officer, Account $account): void
    {
        for ($i = 0; $i < $n; $i++) {
            CollectionSchedule::factory()->create(['due_date' => '2026-10-12', 'field_officer_id' => $officer->id]);
            Transaction::factory()->create(['account_id' => $account->id, 'party_id' => $account->party_id]);
            Transaction::factory()->create(['account_id' => null, 'party_id' => null, 'type' => 'office_income']);
            CashHandover::factory()->create(['field_officer_id' => $officer->id]);
            DailyClosing::factory()->create(['date' => Carbon::parse('2026-09-01')->addDays(DailyClosing::count())->toDateString()]);
            User::factory()->withRole(Role::FieldOfficer)->create();
            Party::factory()->create();
        }
    }
}
