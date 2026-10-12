<?php

namespace App\Http\Requests\Admin;

use App\Rules\TakaAmount;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdjustAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('account')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'contract_amount' => ['required', new TakaAmount],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'contract_amount' => __('app.accounts.new_contract_amount'),
            'reason' => __('app.accounts.reason'),
        ];
    }

    public function contractAmount(): Money
    {
        return Money::fromTaka($this->string('contract_amount')->value());
    }
}
