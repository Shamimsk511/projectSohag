<?php

namespace App\Http\Requests\Admin;

use App\Models\Party;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AssignPartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assign', $this->party()) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'field_officer_id' => [
                'required',
                'integer',
                Rule::in(User::activeFieldOfficers()->pluck('id')->all()),
            ],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $party = $this->party();

                if (! $party->isActive()) {
                    $validator->errors()->add('field_officer_id', __('app.assignments.party_inactive'));
                } elseif ($party->activeAssignment()->where('field_officer_id', $this->integer('field_officer_id'))->exists()) {
                    $validator->errors()->add('field_officer_id', __('app.assignments.already_assigned'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['field_officer_id' => __('app.parties.field_officer')];
    }

    public function officer(): User
    {
        return User::findOrFail($this->integer('field_officer_id'));
    }

    private function party(): Party
    {
        /** @var Party */
        return $this->route('party');
    }
}
