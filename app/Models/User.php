<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property UserStatus $status
 */
#[Fillable(['name', 'email', 'phone', 'password', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * The user's single role. Staff hold exactly one role; null until one is assigned.
     */
    public function currentRole(): ?Role
    {
        $name = $this->getRoleNames()->first();

        return $name === null ? null : Role::from($name);
    }

    /**
     * Named route this user lands on after sign-in, or null when they have no role yet.
     */
    public function homeRoute(): ?string
    {
        return match (true) {
            $this->hasAnyRole(Role::names(Role::office())) => 'admin.dashboard',
            $this->hasRole(Role::FieldOfficer->value) => 'field.home',
            default => null,
        };
    }
}
