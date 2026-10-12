<?php

namespace App\Http\Requests\Field;

use App\Enums\PaymentMethod;
use App\Models\CollectionSchedule;
use App\Rules\TakaAmount;
use App\Support\Money;
use App\Support\TransactionData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A field officer's collection on one of their schedule rows. Ledger rules are checked by TransactionService.
 */
class StoreCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('collect', $this->schedule()) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', new TakaAmount],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'description' => ['nullable', 'string', 'max:500'],
            'client_uuid' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount' => __('app.transactions.amount'),
            'payment_method' => __('app.transactions.payment_method'),
            'description' => __('app.transactions.description'),
        ];
    }

    /**
     * Dated today (Dhaka) and attributed to the signed-in officer.
     */
    public function transactionData(): TransactionData
    {
        $schedule = $this->schedule();
        $account = $schedule->account;

        return new TransactionData(
            type: $account->account_type->collectionType(),
            amount: Money::fromTaka($this->string('amount')->value()),
            date: today(config('app.display_timezone')),
            account: $account,
            paymentMethod: PaymentMethod::from($this->string('payment_method')->value()),
            description: $this->validated('description'),
            clientUuid: $this->string('client_uuid')->lower()->value(),
            fieldOfficer: $this->user(),
            schedule: $schedule,
        );
    }

    private function schedule(): CollectionSchedule
    {
        /** @var CollectionSchedule */
        return $this->route('schedule');
    }
}
