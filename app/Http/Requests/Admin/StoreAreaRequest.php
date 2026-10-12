<?php

namespace App\Http\Requests\Admin;

use App\Enums\ActiveStatus;
use App\Models\Area;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Area::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->areaRules(null);
    }

    /**
     * Shared by create and update; $area is the one being edited (null on create).
     *
     * @return array<string, array<mixed>>
     */
    protected function areaRules(?Area $area): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('areas')->ignore($area)],
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
