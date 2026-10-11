<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent: safe to re-run after adding a case to the Role or Permission enums.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value);
        }

        foreach (Role::cases() as $role) {
            $granted = array_filter(
                Permission::cases(),
                fn (Permission $permission): bool => in_array($role, $permission->roles(), true),
            );

            RoleModel::findOrCreate($role->value)
                ->syncPermissions(array_column($granted, 'value'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
