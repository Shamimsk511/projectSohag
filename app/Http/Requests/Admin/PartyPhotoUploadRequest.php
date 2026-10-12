<?php

namespace App\Http\Requests\Admin;

use App\Models\Party;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PartyPhotoUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Party::class) ?? false;
    }

    /**
     * FilePond posts a metadata string under the same field name as the file; validate the file only.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->allFiles();
    }

    /**
     * Phone camera photos are large; they are scaled down after upload. The dimension cap guards GD memory.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:8192', 'dimensions:max_width=8000,max_height=8000'],
        ];
    }
}
