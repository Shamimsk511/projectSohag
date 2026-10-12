<?php

namespace App\Http\Requests\Admin;

use App\Models\CollectionSchedule;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Hand one officer's (or the unassigned) pending rows for a day to another officer.
 */
class ReassignScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CollectionSchedule::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'from_officer_id' => ['required', Rule::when($this->input('from_officer_id') !== ScheduleIndexRequest::UNASSIGNED, ['integer', 'exists:users,id'])],
            'to_officer_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['to_officer_id' => __('app.schedules.reassign_to')];
    }

    public function day(): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', $this->string('date')->value()) ?: today();
    }

    public function fromOfficer(): ?User
    {
        $from = $this->validated('from_officer_id');

        return $from === ScheduleIndexRequest::UNASSIGNED ? null : User::findOrFail((int) $from);
    }

    public function toOfficer(): User
    {
        return User::findOrFail($this->integer('to_officer_id'));
    }
}
