<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

class RolesAndPermissionsSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_it_creates_every_role_and_permission(): void
    {
        $this->assertEqualsCanonicalizing(
            array_column(Role::cases(), 'value'),
            RoleModel::pluck('name')->all(),
        );
        $this->assertEqualsCanonicalizing(
            array_column(Permission::cases(), 'value'),
            PermissionModel::pluck('name')->all(),
        );
    }

    public function test_running_it_twice_does_not_duplicate_rows(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(count(Role::cases()), RoleModel::count());
        $this->assertSame(count(Permission::cases()), PermissionModel::count());
    }

    public function test_admin_passes_every_permission_check(): void
    {
        $admin = $this->userWithRole(Role::Admin);

        foreach (Permission::cases() as $permission) {
            $this->assertTrue($admin->can($permission->value), $permission->value);
        }
    }

    /**
     * @return array<string, array{Role, Permission, bool}>
     */
    public static function permissionMatrix(): array
    {
        return [
            'manager reverses transactions' => [Role::Manager, Permission::ReverseTransactions, true],
            'manager assigns parties' => [Role::Manager, Permission::AssignParties, true],
            'manager cannot manage users' => [Role::Manager, Permission::ManageUsers, false],
            'manager cannot override limits' => [Role::Manager, Permission::OverrideLimits, false],
            'accountant posts office entries' => [Role::Accountant, Permission::PostOfficeEntries, true],
            'accountant views all reports' => [Role::Accountant, Permission::ViewAllReports, true],
            'accountant cannot assign parties' => [Role::Accountant, Permission::AssignParties, false],
            'accountant cannot reverse transactions' => [Role::Accountant, Permission::ReverseTransactions, false],
            'field officer records collections' => [Role::FieldOfficer, Permission::RecordCollections, true],
            'field officer cannot view all parties' => [Role::FieldOfficer, Permission::ViewAllParties, false],
            'field officer cannot view all reports' => [Role::FieldOfficer, Permission::ViewAllReports, false],
            'field officer cannot reverse transactions' => [Role::FieldOfficer, Permission::ReverseTransactions, false],
        ];
    }

    #[DataProvider('permissionMatrix')]
    public function test_role_permission_matrix(Role $role, Permission $permission, bool $expected): void
    {
        $this->assertSame($expected, $this->userWithRole($role)->can($permission->value));
    }

    private function userWithRole(Role $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
