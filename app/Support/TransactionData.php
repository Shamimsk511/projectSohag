<?php

namespace App\Support;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\CollectionSchedule;
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
        public ?CollectionSchedule $schedule = null,
    ) {}

    /**
     * A field officer's collection on a schedule row (web form and PWA sync): the account's collection
     * type, attributed to the officer.
     */
    public static function forCollection(
        CollectionSchedule $schedule,
        Money $amount,
        Carbon $date,
        PaymentMethod $paymentMethod,
        ?string $description,
        string $clientUuid,
        User $officer,
    ): self {
        return new self(
            type: $schedule->account->account_type->collectionType(),
            amount: $amount,
            date: $date,
            account: $schedule->account,
            paymentMethod: $paymentMethod,
            description: $description,
            clientUuid: strtolower($clientUuid),
            fieldOfficer: $officer,
            schedule: $schedule,
        );
    }
}
