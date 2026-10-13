<?php

namespace App\Http\Requests\Admin;

use App\Enums\ActiveStatus;
use App\Enums\TransactionType;
use App\Models\OfficeHead;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOfficeHeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', OfficeHead::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->headRules(null);
    }

    /**
     * Shared by create and update; $head is the one being edited (null on create).
     *
     * @return array<string, array<mixed>>
     */
    protected function headRules(?OfficeHead $head): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('office_heads')->ignore($head)],
            'kind' => ['required', Rule::in(array_map(fn (TransactionType $type): string => $type->value, TransactionType::officeTypes()))],
            'status' => ['required', Rule::enum(ActiveStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
