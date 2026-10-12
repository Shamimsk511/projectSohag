<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Enums\ScheduleStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Period (default: last 30 Dhaka days up to today), optional status and officer for the missed/partial report.
 */
class MissedPartialReportRequest extends FormRequest
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
            'status' => ['nullable', Rule::in([ScheduleStatus::Missed->value, ScheduleStatus::PartiallyPaid->value])],
            'officer' => ['nullable', 'integer', 'exists:users,id'],
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
        return $this->date('from', '!Y-m-d') ?? $this->periodTo()->subDays(29);
    }

    public function periodTo(): Carbon
    {
        return $this->date('to', '!Y-m-d') ?? Carbon::parse(today(config('app.display_timezone'))->toDateString());
    }

    public function status(): ?ScheduleStatus
    {
        return $this->enum('status', ScheduleStatus::class);
    }

    public function officer(): ?int
    {
        $officer = $this->validated('officer');

        return $officer === null || $officer === '' ? null : (int) $officer;
    }
}
