<?php

namespace App\Http\Requests\Admin;

use App\Models\Area;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdateAreaRequest extends StoreAreaRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->editedArea()) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->areaRules($this->editedArea());
    }

    private function editedArea(): Area
    {
        /** @var Area */
        return $this->route('area');
    }
}
