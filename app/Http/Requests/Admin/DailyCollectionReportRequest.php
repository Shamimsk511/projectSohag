<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Daily collection report filters: one day (default Dhaka today) and optionally one officer.
 */
class DailyCollectionReportRequest extends FormRequest
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
            'date' => ['nullable', 'date_format:Y-m-d'],
            'officer' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function day(): Carbon
    {
        return $this->date('date', '!Y-m-d') ?? Carbon::parse(today(config('app.display_timezone'))->toDateString());
    }

    public function officer(): ?int
    {
        $officer = $this->validated('officer');

        return $officer === null || $officer === '' ? null : (int) $officer;
    }
}
