<?php

namespace App\Http\Requests\Admin;

use App\Models\Holiday;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class StoreHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Holiday::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.today(config('app.display_timezone'))->toDateString()],
            'name' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => __('app.holidays.date'),
            'name' => __('app.holidays.name'),
        ];
    }

    public function holidayDate(): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', $this->string('date')->value()) ?: today();
    }
}
