<?php

namespace App\Http\Requests\Admin;

use App\Models\CollectionSchedule;
use App\Rules\TakaAmount;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * A custom collection date on an account. UpdateScheduleRequest shares the fields.
 */
class StoreScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', CollectionSchedule::class) && $user->can('view', $this->route('account'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.today(config('app.display_timezone'))->toDateString()],
            'expected_amount' => ['required', new TakaAmount],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'due_date' => __('app.schedules.due_date'),
            'expected_amount' => __('app.schedules.expected_amount'),
            'notes' => __('app.schedules.notes'),
        ];
    }

    public function dueDate(): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', $this->string('due_date')->value()) ?: today();
    }

    public function expected(): Money
    {
        return Money::fromTaka($this->string('expected_amount')->value());
    }

    public function notes(): ?string
    {
        return $this->filled('notes') ? $this->string('notes')->trim()->value() : null;
    }
}
