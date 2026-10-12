<?php

namespace App\Http\Requests\Admin;

use App\Enums\PlanFrequency;
use App\Rules\TakaAmount;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SaveCollectionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('account')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'frequency' => ['required', Rule::enum(PlanFrequency::class)],
            'installment_amount' => ['required', new TakaAmount],
            'weekdays' => ['required_if:frequency,'.PlanFrequency::Daily->value, 'array'],
            'weekdays.*' => ['integer', 'between:0,6'],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.today(config('app.display_timezone'))->toDateString()],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'duration' => ['nullable', 'integer', 'between:1,600'],
            'duration_unit' => ['required_with:duration', 'nullable', Rule::in(['months', 'years'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'frequency' => __('app.schedules.frequency'),
            'installment_amount' => __('app.schedules.installment_amount'),
            'weekdays' => __('app.schedules.weekdays'),
            'starts_on' => __('app.schedules.starts_on'),
            'ends_on' => __('app.schedules.ends_on'),
            'duration' => __('app.schedules.duration'),
            'duration_unit' => __('app.schedules.duration_unit'),
        ];
    }

    public function frequency(): PlanFrequency
    {
        return $this->enum('frequency', PlanFrequency::class) ?? PlanFrequency::Daily;
    }

    public function installment(): Money
    {
        return Money::fromTaka($this->string('installment_amount')->value());
    }

    /**
     * @return list<int>|null
     */
    public function weekdays(): ?array
    {
        if ($this->frequency() !== PlanFrequency::Daily) {
            return null;
        }

        return array_values(array_map(intval(...), (array) $this->validated('weekdays', [])));
    }

    public function startsOn(): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', $this->string('starts_on')->value()) ?: today();
    }

    /**
     * The entered end date, else the day before start + term (3 years from 12 Oct ends 11 Oct).
     */
    public function endsOn(): ?Carbon
    {
        if ($this->filled('ends_on')) {
            return Carbon::createFromFormat('!Y-m-d', $this->string('ends_on')->value()) ?: null;
        }

        if (! $this->filled('duration')) {
            return null;
        }

        $months = $this->integer('duration') * ($this->validated('duration_unit') === 'years' ? 12 : 1);

        return $this->startsOn()->addMonthsNoOverflow($months)->subDay();
    }
}
