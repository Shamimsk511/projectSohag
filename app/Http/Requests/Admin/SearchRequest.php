<?php

namespace App\Http\Requests\Admin;

use App\Models\Party;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function term(): string
    {
        return trim($this->string('q')->value());
    }
}
