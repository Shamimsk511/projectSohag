<?php

namespace App\Http\Requests\Admin;

use App\Enums\CashHandoverMode;
use App\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::ManageSettings->value) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cash_handover_mode' => ['required', Rule::enum(CashHandoverMode::class)],
            'auto_close_days' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cash_handover_mode' => __('app.settings.cash_handover_mode'),
            'auto_close_days' => __('app.settings.auto_close_days'),
        ];
    }

    public function cashHandoverMode(): CashHandoverMode
    {
        return $this->enum('cash_handover_mode', CashHandoverMode::class) ?? CashHandoverMode::Manual;
    }
}
