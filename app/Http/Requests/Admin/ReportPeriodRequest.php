<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Period for the period reports (officer performance, cash reconciliation); defaults to the current Dhaka month up to today.
 */
class ReportPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::ViewAllReports->value) ?? false;
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
        return $this->date('from', '!Y-m-d') ?? Carbon::parse(today(config('app.display_timezone'))->startOfMonth()->toDateString());
    }

    public function periodTo(): Carbon
    {
        return $this->date('to', '!Y-m-d') ?? Carbon::parse(today(config('app.display_timezone'))->toDateString());
    }
}
