<?php

namespace Tests\Feature;

use App\Enums\ActiveStatus;
use App\Enums\Role;
use App\Models\Area;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AreaManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_manager_lists_creates_and_updates_areas(): void
    {
        $manager = User::factory()->withRole(Role::Manager)->create();
        $existing = Area::factory()->create(['name' => 'Mirpur']);

        $this->actingAs($manager)->get(route('admin.areas.index'))->assertOk()->assertSee('Mirpur');

        $this->actingAs($manager)
            ->post(route('admin.areas.store'), ['name' => 'Uttara', 'status' => ActiveStatus::Active->value])
            ->assertRedirect(route('admin.areas.index'));
        $this->assertDatabaseHas('areas', ['name' => 'Uttara', 'status' => 'active']);

        $this->actingAs($manager)
            ->put(route('admin.areas.update', $existing), ['name' => 'Mirpur 10', 'status' => ActiveStatus::Inactive->value])
            ->assertRedirect(route('admin.areas.index'));
        $this->assertDatabaseHas('areas', ['id' => $existing->id, 'name' => 'Mirpur 10', 'status' => 'inactive']);
    }

    public function test_area_name_must_be_unique(): void
    {
        $manager = User::factory()->withRole(Role::Manager)->create();
        $mirpur = Area::factory()->create(['name' => 'Mirpur']);

        $this->actingAs($manager)
            ->post(route('admin.areas.store'), ['name' => 'Mirpur', 'status' => 'active'])
            ->assertSessionHasErrors('name');

        $this->actingAs($manager)
            ->put(route('admin.areas.update', $mirpur), ['name' => 'Mirpur', 'status' => 'active'])
            ->assertSessionHasNoErrors();
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function rolesWithoutAreaAccess(): array
    {
        return [
            'accountant' => [Role::Accountant],
            'field officer' => [Role::FieldOfficer],
        ];
    }

    #[DataProvider('rolesWithoutAreaAccess')]
    public function test_other_roles_cannot_manage_areas(Role $role): void
    {
        $user = User::factory()->withRole($role)->create();
        $area = Area::factory()->create();

        $this->actingAs($user)->get(route('admin.areas.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.areas.store'), ['name' => 'X', 'status' => 'active'])->assertForbidden();
        $this->actingAs($user)->put(route('admin.areas.update', $area), ['name' => 'X', 'status' => 'active'])->assertForbidden();
    }
}
