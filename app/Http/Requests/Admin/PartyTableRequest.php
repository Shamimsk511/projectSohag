<?php

namespace App\Http\Requests\Admin;

use App\Enums\ActiveStatus;
use App\Models\Party;
use App\Services\PartyTableQuery;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * DataTables server-side parameters for the party list.
 */
class PartyTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Party::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'draw' => ['nullable', 'integer'],
            'start' => ['nullable', 'integer', 'min:0'],
            'length' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search.value' => ['nullable', 'string', 'max:100'],
            'order' => ['nullable', 'array', 'max:1'],
            'order.*.column' => ['required', 'integer', Rule::in(array_keys(PartyTableQuery::ORDERABLE))],
            'order.*.dir' => ['required', Rule::in(['asc', 'desc'])],
            'area_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(ActiveStatus::class)],
        ];
    }
}
