<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Models\OfficeHead;
use App\Rules\TakaAmount;
use App\Support\ClientFee;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Optional fee fields (`fee_head_id`, `fee_amount`) on a form that posts another entry. Only staff who
 * may post office entries can send them; the head's kind and state are checked by TransactionService.
 */
trait TakesClientFee
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function feeRules(): array
    {
        $allowed = $this->user()?->can(Permission::PostOfficeEntries->value) ?? false;

        return [
            'fee_head_id' => [$allowed ? 'nullable' : 'prohibited', 'required_with:fee_amount', 'integer', Rule::exists('office_heads', 'id')],
            'fee_amount' => [$allowed ? 'nullable' : 'prohibited', 'required_with:fee_head_id', new TakaAmount],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function feeAttributes(): array
    {
        return [
            'fee_head_id' => __('app.office_heads.fee_head'),
            'fee_amount' => __('app.office_heads.fee_amount'),
        ];
    }

    protected function clientFee(PaymentMethod $paymentMethod): ?ClientFee
    {
        if (! $this->filled('fee_amount')) {
            return null;
        }

        return new ClientFee(
            OfficeHead::query()->findOrFail($this->integer('fee_head_id')),
            Money::fromTaka($this->string('fee_amount')->value()),
            $paymentMethod,
        );
    }
}
