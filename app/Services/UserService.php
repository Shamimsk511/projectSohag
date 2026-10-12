<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Create a staff account holding exactly one role. $actor is null when created from the console.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, Role $role, ?User $actor): User
    {
        return DB::transaction(function () use ($attributes, $role, $actor): User {
            $user = User::create($attributes);
            $user->syncRoles([$role->value]);
            $this->audit->created('user.created', $user, $actor, ['role' => $role->value]);

            return $user;
        });
    }

    /**
     * Update a staff account and replace its role. A blank password keeps the current one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes, Role $role, User $actor): User
    {
        if (blank($attributes['password'] ?? null)) {
            unset($attributes['password']);
        }

        return DB::transaction(function () use ($user, $attributes, $role, $actor): User {
            $oldRole = $user->getRoleNames()->first();
            $user->update($attributes);
            $user->syncRoles([$role->value]);

            $roleChanged = $oldRole !== $role->value;
            $this->audit->updated(
                'user.updated',
                $user,
                $actor,
                $roleChanged ? ['role' => $oldRole] : [],
                $roleChanged ? ['role' => $role->value] : [],
            );

            return $user;
        });
    }
}
