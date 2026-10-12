<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Numerals;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->userRules(null);
    }

    /**
     * Shared by create and update; $user is the account being edited (null on create).
     *
     * @return array<string, array<mixed>>
     */
    protected function userRules(?User $user): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'phone' => ['nullable', 'string', 'regex:/^01[3-9][0-9]{8}$/', Rule::unique('users')->ignore($user)],
            'password' => [$user === null ? 'required' : 'nullable', 'string', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::enum(Role::class)],
            'status' => ['required', Rule::enum(UserStatus::class)],
        ];
    }

    /**
     * Officers often type phone numbers in Bengali digits; store them as ASCII.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => Numerals::toAscii(trim($this->input('phone')))]);
        }
    }
}
