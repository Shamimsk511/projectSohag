<?php

namespace App\Support;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\OfficeHead;
use Illuminate\Support\Carbon;

/**
 * A fee taken from the member alongside another entry (loan given, withdrawal...): its own office
 * income row linked to the account, never netted into the main entry.
 */
final readonly class ClientFee
{
    public function __construct(
        public OfficeHead $head,
        public Money $amount,
        public PaymentMethod $paymentMethod = PaymentMethod::Cash,
    ) {}

    public function entryFor(Account $account, Carbon $date): TransactionData
    {
        return new TransactionData(
            type: TransactionType::OfficeIncome,
            amount: $this->amount,
            date: $date,
            account: $account,
            paymentMethod: $this->paymentMethod,
            officeHead: $this->head,
        );
    }
}
