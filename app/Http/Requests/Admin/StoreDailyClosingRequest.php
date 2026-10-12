<?php

namespace App\Http\Requests\Admin;

use App\Models\DailyClosing;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Close a business date. Future and already-closed dates are rejected by CashService.
 */
class StoreDailyClosingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DailyClosing::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => __('app.closings.date'),
        ];
    }

    public function closingDate(): Carbon
    {
        return $this->date('date', 'Y-m-d') ?? throw new LogicException('date is validated as required.');
    }
}
