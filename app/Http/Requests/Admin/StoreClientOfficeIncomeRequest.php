<?php

namespace App\Http\Requests\Admin;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\OfficeHead;
use App\Models\Transaction;
use App\Rules\TakaAmount;
use App\Support\Money;
use App\Support\TransactionData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Office income taken from the account's party (fee, form...), linked to the account. The head's kind
 * and state are checked by TransactionService.
 */
class StoreClientOfficeIncomeRequest extends FormRequest
{
    /** The account page has other forms with the same field names. */
    protected $errorBag = 'officeIncome';

    public function authorize(): bool
    {
        return $this->user()?->can('createOfficeIncome', [Transaction::class, $this->account()]) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'office_head_id' => ['required', 'integer', Rule::exists('office_heads', 'id')],
            'amount' => ['required', new TakaAmount],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
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
            'office_head_id' => __('app.office_heads.head'),
            'amount' => __('app.transactions.amount'),
            'transaction_date' => __('app.transactions.date'),
            'payment_method' => __('app.transactions.payment_method'),
            'reference' => __('app.transactions.reference'),
            'description' => __('app.transactions.description'),
        ];
    }

    public function transactionData(): TransactionData
    {
        return new TransactionData(
            type: TransactionType::OfficeIncome,
            amount: Money::fromTaka($this->string('amount')->value()),
            date: $this->date('transaction_date', 'Y-m-d') ?? throw new LogicException('transaction_date is validated as required.'),
            account: $this->account(),
            paymentMethod: PaymentMethod::from($this->string('payment_method')->value()),
            reference: $this->validated('reference'),
            description: $this->validated('description'),
            officeHead: OfficeHead::query()->findOrFail($this->integer('office_head_id')),
        );
    }

    private function account(): Account
    {
        /** @var Account */
        return $this->route('account');
    }
}
