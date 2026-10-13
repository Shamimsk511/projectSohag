<?php

namespace App\Http\Requests\Admin;

use App\Models\OfficeHead;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdateOfficeHeadRequest extends StoreOfficeHeadRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->editedHead()) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->headRules($this->editedHead());
    }

    private function editedHead(): OfficeHead
    {
        /** @var OfficeHead */
        return $this->route('office_head');
    }
}
