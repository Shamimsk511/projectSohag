<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccountType;
use App\Models\Account;
use App\Rules\TakaAmount;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Account::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'account_type' => ['required', Rule::enum(AccountType::class)],
            'contract_amount' => [
                'required_if:account_type,'.AccountType::Loan->value,
                'prohibited_unless:account_type,'.AccountType::Loan->value,
                'nullable',
                new TakaAmount,
            ],
            'opened_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.today(config('app.display_timezone'))->toDateString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'account_type' => __('app.accounts.type'),
            'contract_amount' => __('app.accounts.contract_amount'),
            'opened_at' => __('app.accounts.opened_at'),
        ];
    }

    public function accountType(): AccountType
    {
        return $this->enum('account_type', AccountType::class) ?? AccountType::Saving;
    }

    public function contractAmount(): ?Money
    {
        return $this->filled('contract_amount') ? Money::fromTaka($this->string('contract_amount')->value()) : null;
    }

    public function openedAt(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('opened_at')->value()) ?: today();
    }
}
