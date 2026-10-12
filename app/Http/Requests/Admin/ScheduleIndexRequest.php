<?php

namespace App\Http\Requests\Admin;

use App\Enums\ScheduleStatus;
use App\Models\CollectionSchedule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Filters for the schedule list: one day (default Dhaka today), optional officer and status.
 */
class ScheduleIndexRequest extends FormRequest
{
    public const UNASSIGNED = 'none';

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', CollectionSchedule::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'officer' => ['nullable', Rule::when($this->input('officer') !== self::UNASSIGNED, ['integer', 'exists:users,id'])],
            'status' => ['nullable', Rule::enum(ScheduleStatus::class)],
        ];
    }

    public function day(): Carbon
    {
        return $this->date('date', '!Y-m-d') ?? Carbon::parse(today(config('app.display_timezone'))->toDateString());
    }

    /**
     * Null = all officers, UNASSIGNED = rows without officer, else a user id.
     */
    public function officer(): int|string|null
    {
        $officer = $this->validated('officer');

        return match (true) {
            $officer === null || $officer === '' => null,
            $officer === self::UNASSIGNED => self::UNASSIGNED,
            default => (int) $officer,
        };
    }

    public function status(): ?ScheduleStatus
    {
        return $this->enum('status', ScheduleStatus::class);
    }
}
