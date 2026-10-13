<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccountType;
use App\Rules\TakaAmount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The loan open form while it is being typed: same input and math as opening, nothing required but
 * the loan amount and nothing stored.
 */
class LoanPreviewRequest extends StoreAccountRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'account_type' => ['nullable', Rule::enum(AccountType::class)],
            'principal_amount' => ['required', new TakaAmount],
            'extra_amount' => ['nullable', new TakaAmount],
            'existing_loan' => ['nullable', 'boolean'],
            'received_amount' => ['nullable', new TakaAmount],
            'opened_at' => ['nullable', 'date_format:Y-m-d'],
            ...$this->collectionRules(),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [];
    }

    public function accountType(): AccountType
    {
        return AccountType::Loan;
    }
}
