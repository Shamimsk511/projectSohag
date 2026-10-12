<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends StoreUserRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->editedUser()) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->userRules($this->editedUser());
    }

    /**
     * Stop an admin from locking themselves out.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->editedUser()->is($this->user())) {
                    return;
                }

                if ($this->input('role') !== Role::Admin->value) {
                    $validator->errors()->add('role', __('app.users.cannot_demote_self'));
                }

                if ($this->input('status') !== UserStatus::Active->value) {
                    $validator->errors()->add('status', __('app.users.cannot_deactivate_self'));
                }
            },
        ];
    }

    private function editedUser(): User
    {
        /** @var User */
        return $this->route('user');
    }
}
