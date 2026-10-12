<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Area;
use App\Models\Party;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartyTableTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->accountant = User::factory()->withRole(Role::Accountant)->create();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function tableParams(array $params = []): array
    {
        return array_replace_recursive([
            'draw' => 3,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'order' => [['column' => 1, 'dir' => 'asc']],
        ], $params);
    }

    public function test_returns_datatables_payload_with_counts_and_rows(): void
    {
        $area = Area::factory()->create(['name' => 'Mirpur']);
        Party::factory()->for($area)->create(['name' => 'Amina', 'phone' => '01711000001']);
        Party::factory()->count(2)->create();

        $this->actingAs($this->accountant)
            ->getJson(route('admin.parties.data', $this->tableParams()))
            ->assertOk()
            ->assertJsonPath('draw', 3)
            ->assertJsonPath('recordsTotal', 3)
            ->assertJsonPath('recordsFiltered', 3)
            ->assertJsonCount(3, 'data')
            ->assertJsonFragment(['name' => 'Amina', 'phone' => '01711000001', 'area' => 'Mirpur']);
    }

    public function test_search_matches_code_name_phone_and_national_id(): void
    {
        $amina = Party::factory()->create(['name' => 'Amina Begum', 'phone' => '01711000001', 'national_id' => '9876543210']);
        Party::factory()->create(['name' => 'Karim', 'phone' => '01911000002', 'national_id' => null]);

        foreach (['amina', '01711000001', '9876543210', $amina->party_code, '০১৭১১০০০০০১'] as $term) {
            $this->actingAs($this->accountant)
                ->getJson(route('admin.parties.data', $this->tableParams(['search' => ['value' => $term]])))
                ->assertJsonPath('recordsTotal', 2)
                ->assertJsonPath('recordsFiltered', 1)
                ->assertJsonPath('data.0.name', 'Amina Begum');
        }
    }

    public function test_like_wildcards_in_search_are_literal(): void
    {
        Party::factory()->count(2)->create();

        $this->actingAs($this->accountant)
            ->getJson(route('admin.parties.data', $this->tableParams(['search' => ['value' => '%']])))
            ->assertJsonPath('recordsFiltered', 0);
    }

    public function test_filters_by_area_and_status(): void
    {
        $area = Area::factory()->create();
        Party::factory()->for($area)->create(['name' => 'Match']);
        Party::factory()->for($area)->inactive()->create();
        Party::factory()->create();

        $this->actingAs($this->accountant)
            ->getJson(route('admin.parties.data', $this->tableParams(['area_id' => $area->id, 'status' => 'active'])))
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.name', 'Match');
    }

    public function test_pages_and_orders_by_whitelisted_columns_only(): void
    {
        foreach (['Charlie', 'Alpha', 'Bravo'] as $name) {
            Party::factory()->create(['name' => $name]);
        }

        $this->actingAs($this->accountant)
            ->getJson(route('admin.parties.data', $this->tableParams(['start' => 1, 'length' => 1, 'order' => [['column' => 1, 'dir' => 'desc']]])))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Bravo');

        $this->actingAs($this->accountant)
            ->getJson(route('admin.parties.data', $this->tableParams(['order' => [['column' => 99, 'dir' => 'sideways']]])))
            ->assertUnprocessable();
    }

    public function test_page_length_is_capped(): void
    {
        $this->actingAs($this->accountant)
            ->getJson(route('admin.parties.data', $this->tableParams(['length' => 100000])))
            ->assertUnprocessable();
    }

    public function test_edit_url_is_only_returned_to_users_who_can_edit(): void
    {
        Party::factory()->create();
        $manager = User::factory()->withRole(Role::Manager)->create();

        $this->actingAs($this->accountant)
            ->getJson(route('admin.parties.data', $this->tableParams()))
            ->assertJsonPath('data.0.edit_url', null);

        $this->actingAs($manager)
            ->getJson(route('admin.parties.data', $this->tableParams()))
            ->assertJsonPath('data.0.edit_url', route('admin.parties.edit', Party::sole()));
    }
}
