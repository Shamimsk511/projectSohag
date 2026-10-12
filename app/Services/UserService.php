<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserService
{
    /**
     * Create a staff account holding exactly one role.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, Role $role): User
    {
        return DB::transaction(function () use ($attributes, $role): User {
            $user = User::create($attributes);
            $user->syncRoles([$role->value]);

            return $user;
        });
    }

    /**
     * Update a staff account and replace its role. A blank password keeps the current one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes, Role $role): User
    {
        if (blank($attributes['password'] ?? null)) {
            unset($attributes['password']);
        }

        return DB::transaction(function () use ($user, $attributes, $role): User {
            $user->update($attributes);
            $user->syncRoles([$role->value]);

            return $user;
        });
    }
}
