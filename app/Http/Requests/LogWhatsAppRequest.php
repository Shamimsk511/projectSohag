<?php

namespace App\Http\Requests;

use App\Enums\WhatsAppKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A WhatsApp button tap. The route's role middleware gates the area; the row itself is authorized in the controller.
 */
class LogWhatsAppRequest extends FormRequest
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
            'kind' => ['required', Rule::enum(WhatsAppKind::class)],
            'schedule_id' => ['required_if:kind,reminder', 'nullable', 'integer', 'exists:collection_schedules,id'],
            'transaction_id' => ['required_if:kind,receipt,notice', 'nullable', 'integer', 'exists:transactions,id'],
            'party_id' => ['required_if:kind,portal', 'nullable', 'integer', 'exists:parties,id'],
        ];
    }

    public function kind(): WhatsAppKind
    {
        return WhatsAppKind::from($this->string('kind')->value());
    }
}
