<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters for the outstanding report: optional area, and only accounts with overdue rows.
 */
class OutstandingReportRequest extends FormRequest
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
            'area' => ['nullable', 'integer', 'exists:areas,id'],
            'overdue' => ['nullable', 'boolean'],
        ];
    }

    public function area(): ?int
    {
        $area = $this->validated('area');

        return $area === null || $area === '' ? null : (int) $area;
    }

    public function overdueOnly(): bool
    {
        return $this->boolean('overdue');
    }
}
