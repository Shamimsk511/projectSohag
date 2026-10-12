<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Optional statement period; either end may be left open.
 */
class AccountStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('account')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['from' => __('app.accounts.statement_from'), 'to' => __('app.accounts.statement_to')];
    }

    public function periodFrom(): ?Carbon
    {
        return $this->date('from', 'Y-m-d')?->startOfDay();
    }

    public function periodTo(): ?Carbon
    {
        return $this->date('to', 'Y-m-d')?->startOfDay();
    }
}
