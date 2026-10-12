<?php

namespace App\Http\Requests\Admin;

use App\Models\Party;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdatePartyRequest extends StorePartyRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->editedParty()) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->partyRules($this->editedParty());
    }

    private function editedParty(): Party
    {
        /** @var Party */
        return $this->route('party');
    }
}
