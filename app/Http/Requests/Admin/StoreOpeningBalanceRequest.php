<?php

namespace App\Http\Requests\Admin;

use App\Models\OpeningBalance;
use App\Rules\TakaAmount;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Office cash at go-live. Once-only and date-order rules are checked by OpeningBalanceService.
 */
class StoreOpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', OpeningBalance::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', new TakaAmount],
            'as_of' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount' => __('app.opening_cash.amount'),
            'as_of' => __('app.opening_cash.as_of'),
            'note' => __('app.opening_cash.note'),
        ];
    }

    public function amount(): Money
    {
        return Money::fromTaka($this->string('amount')->value());
    }

    public function asOf(): Carbon
    {
        return $this->date('as_of', 'Y-m-d') ?? throw new LogicException('as_of is validated as required.');
    }
}
