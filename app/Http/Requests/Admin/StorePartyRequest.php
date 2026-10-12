<?php

namespace App\Http\Requests\Admin;

use App\Enums\ActiveStatus;
use App\Enums\Gender;
use App\Models\Party;
use App\Services\PartyPhotoStore;
use App\Support\Numerals;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartyRequest extends FormRequest
{
    private const string PHONE = 'regex:/^01[3-9][0-9]{8}$/';

    /** Bangladeshi NID: 10 (smart card), 13 or 17 digits (old format). */
    private const string NATIONAL_ID = 'regex:/^([0-9]{10}|[0-9]{13}|[0-9]{17})$/';

    public function authorize(): bool
    {
        return $this->user()?->can('create', Party::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->partyRules(null);
    }

    /**
     * Shared by create and update; $party is the one being edited (null on create).
     *
     * @return array<string, array<mixed>>
     */
    protected function partyRules(?Party $party): array
    {
        // A party may keep an area that was deactivated after it was assigned.
        $keepsCurrentArea = $party !== null && (string) $this->input('area_id') === (string) $party->area_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'father_or_husband_name' => ['nullable', 'string', 'max:255'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'phone' => ['required', 'string', self::PHONE],
            'national_id' => ['nullable', 'string', self::NATIONAL_ID, Rule::unique('parties')->ignore($party)],
            'address' => ['required', 'string', 'max:500'],
            'area_id' => $keepsCurrentArea
                ? ['required', 'integer']
                : ['required', 'integer', Rule::exists('areas', 'id')->where('status', ActiveStatus::Active->value)],
            'nominee_name' => ['nullable', 'string', 'max:255'],
            'nominee_relation' => ['nullable', 'string', 'max:100'],
            'nominee_phone' => ['nullable', 'string', self::PHONE],
            'nominee_national_id' => ['nullable', 'string', self::NATIONAL_ID],
            'status' => ['required', Rule::enum(ActiveStatus::class)],
            // Token from the FilePond upload endpoint; only tokens issued to this session are accepted.
            'photo' => ['nullable', 'string', Rule::in($this->session()->get(PartyPhotoStore::SESSION_KEY, []))],
        ];
    }

    /**
     * Staff often type numbers in Bengali digits; store them as ASCII.
     */
    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (['phone', 'national_id', 'nominee_phone', 'nominee_national_id'] as $field) {
            if (is_string($this->input($field))) {
                $normalised[$field] = Numerals::toAscii(str_replace([' ', '-'], '', $this->input($field)));
            }
        }

        $this->merge($normalised);
    }
}
