<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * The dashboard day (default Dhaka today). Access is gated by the /admin role middleware.
 */
class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function day(): Carbon
    {
        return $this->date('date', '!Y-m-d') ?? Carbon::parse(today(config('app.display_timezone'))->toDateString());
    }
}
