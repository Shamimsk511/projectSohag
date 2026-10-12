<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * The month (Y-m) for the monthly report; defaults to the current Dhaka month.
 */
class MonthlyReportRequest extends FormRequest
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
            'month' => ['nullable', 'date_format:Y-m'],
        ];
    }

    public function month(): Carbon
    {
        return $this->date('month', '!Y-m') ?? Carbon::parse(today(config('app.display_timezone'))->startOfMonth()->toDateString());
    }
}
