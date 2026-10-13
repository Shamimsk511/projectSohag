<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccountType;
use App\Enums\PaymentMethod;
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
            'principal_amount' => ['required_if:account_type,'.AccountType::Loan->value, 'nullable', new TakaAmount],
            'extra_amount' => ['nullable', new TakaAmount],
            'contract_amount' => ['nullable', new TakaAmount],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'existing_loan' => ['nullable', 'boolean'],
            'received_amount' => ['nullable', new TakaAmount],
            'carried_as_of' => ['required_if:existing_loan,1', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.today(config('app.display_timezone'))->toDateString()],
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
            'principal_amount' => __('app.accounts.principal_amount'),
            'extra_amount' => __('app.accounts.agreed_extra'),
            'contract_amount' => __('app.accounts.agreed_amount'),
            'payment_method' => __('app.transactions.payment_method'),
            'received_amount' => __('app.accounts.received_before'),
            'carried_as_of' => __('app.accounts.carried_as_of'),
            'opened_at' => __('app.accounts.opened_at'),
        ];
    }

    public function accountType(): AccountType
    {
        return $this->enum('account_type', AccountType::class) ?? AccountType::Saving;
    }

    /**
     * Saving or FDR: the optional agreed amount (end-of-term amount or agreed payback).
     */
    public function agreedAmount(): ?Money
    {
        return $this->filled('contract_amount') ? Money::fromTaka($this->string('contract_amount')->value()) : null;
    }

    public function principalAmount(): Money
    {
        return Money::fromTaka($this->string('principal_amount')->value());
    }

    public function agreedExtra(): Money
    {
        return $this->filled('extra_amount') ? Money::fromTaka($this->string('extra_amount')->value()) : Money::zero();
    }

    /**
     * Admin only (checked by AccountService): the loan existed before go-live.
     */
    public function isExistingLoan(): bool
    {
        return $this->accountType() === AccountType::Loan && $this->boolean('existing_loan');
    }

    public function receivedBefore(): Money
    {
        return $this->filled('received_amount') ? Money::fromTaka($this->string('received_amount')->value()) : Money::zero();
    }

    public function carriedAsOf(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('carried_as_of')->value()) ?: today();
    }

    public function paymentMethod(): PaymentMethod
    {
        return $this->enum('payment_method', PaymentMethod::class) ?? PaymentMethod::Cash;
    }

    public function openedAt(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('opened_at')->value()) ?: today();
    }
}
