<?php

namespace App\Support;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Input for TransactionService::record(). Built by screens and the PWA sync from validated input.
 */
final readonly class TransactionData
{
    public function __construct(
        public TransactionType $type,
        public Money $amount,
        public Carbon $date,
        public ?Account $account = null,
        public PaymentMethod $paymentMethod = PaymentMethod::Cash,
        public ?string $reference = null,
        public ?string $description = null,
        public ?string $clientUuid = null,
        public ?User $fieldOfficer = null,
        public ?string $overrideReason = null,
    ) {}
}
