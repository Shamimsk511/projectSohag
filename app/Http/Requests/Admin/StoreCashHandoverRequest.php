<?php

namespace App\Http\Requests\Admin;

use App\Models\CashHandover;
use App\Models\User;
use App\Rules\TakaAmount;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Cash received from a field officer. Role, cash-in-hand and closed-day rules live in CashService.
 */
class StoreCashHandoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CashHandover::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'field_officer_id' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', new TakaAmount],
            'handover_date' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'field_officer_id' => __('app.cash.officer'),
            'amount' => __('app.cash.amount'),
            'handover_date' => __('app.cash.handover_date'),
            'note' => __('app.cash.note'),
        ];
    }

    public function officer(): User
    {
        return User::query()->findOrFail($this->integer('field_officer_id'));
    }

    public function amount(): Money
    {
        return Money::fromTaka($this->string('amount')->value());
    }

    public function handoverDate(): Carbon
    {
        return $this->date('handover_date', 'Y-m-d') ?? throw new LogicException('handover_date is validated as required.');
    }

    public function note(): ?string
    {
        $note = $this->string('note')->trim()->value();

        return $note === '' ? null : $note;
    }
}
