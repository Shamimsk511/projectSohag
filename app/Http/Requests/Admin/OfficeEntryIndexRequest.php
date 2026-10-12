<?php

namespace App\Http\Requests\Admin;

use App\Models\Transaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Period for the office entries list. Missing ends default to the current Dhaka month.
 */
class OfficeEntryIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewOffice', Transaction::class) ?? false;
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

    public function periodFrom(): Carbon
    {
        return $this->date('from', 'Y-m-d')?->startOfDay() ?? today(config('app.display_timezone'))->startOfMonth();
    }

    public function periodTo(): Carbon
    {
        return $this->date('to', 'Y-m-d')?->startOfDay() ?? today(config('app.display_timezone'))->endOfMonth()->startOfDay();
    }
}
