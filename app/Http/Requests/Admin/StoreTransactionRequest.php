<?php

namespace App\Http\Requests\Admin;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\CollectionSchedule;
use App\Models\Transaction;
use App\Rules\TakaAmount;
use App\Support\Money;
use App\Support\TransactionData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Office entry on one account, optionally settling one of its collection rows. Balance, date and account-state rules are checked by TransactionService.
 */
class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Transaction::class, $this->account()]) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_map(
                fn (TransactionType $type): string => $type->value,
                $this->account()->account_type->entryTypes(),
            ))],
            'amount' => ['required', new TakaAmount],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'schedule_id' => ['nullable', 'integer', Rule::exists('collection_schedules', 'id')],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => __('app.transactions.type'),
            'amount' => __('app.transactions.amount'),
            'transaction_date' => __('app.transactions.date'),
            'payment_method' => __('app.transactions.payment_method'),
            'schedule_id' => __('app.transactions.schedule'),
            'reference' => __('app.transactions.reference'),
            'description' => __('app.transactions.description'),
        ];
    }

    public function transactionData(): TransactionData
    {
        return new TransactionData(
            type: TransactionType::from($this->string('type')->value()),
            amount: Money::fromTaka($this->string('amount')->value()),
            date: $this->date('transaction_date', 'Y-m-d') ?? throw new LogicException('transaction_date is validated as required.'),
            account: $this->account(),
            paymentMethod: PaymentMethod::from($this->string('payment_method')->value()),
            reference: $this->validated('reference'),
            description: $this->validated('description'),
            schedule: $this->filled('schedule_id') ? CollectionSchedule::query()->find($this->integer('schedule_id')) : null,
        );
    }

    private function account(): Account
    {
        /** @var Account */
        return $this->route('account');
    }
}
