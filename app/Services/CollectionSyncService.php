<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Exceptions\LedgerRuleViolation;
use App\Models\CollectionSchedule;
use App\Models\Transaction;
use App\Models\User;
use App\Rules\TakaAmount;
use App\Support\Money;
use App\Support\TransactionData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Posts the PWA's offline collections (protocol in docs/PWA.md). Each item goes through
 * TransactionService on its own, so one rejected item never blocks the rest of the batch.
 */
class CollectionSyncService
{
    public const MAX_ITEMS = 50;

    /**
     * How many days before today (Dhaka) an offline collection may be dated. Older ones go through the office.
     */
    private const BACKDATE_DAYS = 1;

    public function __construct(private readonly TransactionService $transactions) {}

    /**
     * @param  list<array<string, mixed>>  $items  in the order collected
     * @return list<array<string, mixed>> one result per item, same order
     */
    public function sync(array $items, User $officer): array
    {
        return array_map(fn (array $item): array => $this->syncItem($item, $officer), $items);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function syncItem(array $item, User $officer): array
    {
        $uuid = strtolower((string) $item['client_uuid']);

        $validator = Validator::make($item, [
            'schedule_id' => ['required', 'integer'],
            'amount' => ['required', 'string', new TakaAmount],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'description' => ['nullable', 'string', 'max:500'],
            'collected_at' => ['required', 'date'],
        ]);
        if ($validator->fails()) {
            return $this->rejected($uuid, 'invalid', __('app.sync.invalid'), $officer, $validator->errors()->toArray());
        }
        $amount = Money::fromTaka((string) $item['amount']);
        $scheduleId = (int) $item['schedule_id'];

        // A resend after a lost response: answer from the stored row, even if the row was reassigned since.
        $existing = Transaction::query()->where('client_uuid', $uuid)->first();
        if ($existing) {
            return $this->replayed($existing, $scheduleId, $amount, $officer);
        }

        $schedule = CollectionSchedule::query()->with('account')->find($scheduleId);
        if (! $schedule || Gate::forUser($officer)->denies('collect', $schedule)) {
            return $this->rejected($uuid, 'forbidden', __('app.sync.forbidden'), $officer);
        }

        $timezone = config('app.display_timezone');
        $date = Carbon::parse((string) $item['collected_at'])->setTimezone($timezone)->startOfDay();
        $today = today($timezone);
        if ($date->toDateString() > $today->toDateString()
            || $date->toDateString() < $today->copy()->subDays(self::BACKDATE_DAYS)->toDateString()) {
            return $this->rejected($uuid, 'date_out_of_range', __('app.sync.date_out_of_range'), $officer);
        }

        try {
            $transaction = $this->transactions->record(TransactionData::forCollection(
                schedule: $schedule,
                amount: $amount,
                date: $date,
                paymentMethod: PaymentMethod::from((string) $item['payment_method']),
                description: isset($item['description']) ? (string) $item['description'] : null,
                clientUuid: $uuid,
                officer: $officer,
            ), $officer);
        } catch (LedgerRuleViolation $exception) {
            return $this->rejected($uuid, $exception->rule, (string) collect($exception->errors())->flatten()->first(), $officer);
        } catch (Throwable $exception) {
            report($exception);

            return ['client_uuid' => $uuid, 'status' => 'error'];
        }

        // Lost a race with a concurrent resend of the same uuid.
        return $transaction->wasRecentlyCreated
            ? $this->accepted($transaction, 'created')
            : $this->replayed($transaction, $scheduleId, $amount, $officer);
    }

    /**
     * Same uuid, same officer, row and amount: the item was already recorded. Anything else is a conflict
     * and nothing about the stored row is revealed.
     *
     * @return array<string, mixed>
     */
    private function replayed(Transaction $existing, int $scheduleId, Money $amount, User $officer): array
    {
        $samePayload = $existing->field_officer_id === $officer->id
            && $existing->schedule_id === $scheduleId
            && $existing->amount->equals($amount);

        return $samePayload
            ? $this->accepted($existing, 'duplicate')
            : $this->rejected((string) $existing->client_uuid, 'uuid_conflict', __('app.sync.uuid_conflict'), $officer);
    }

    /**
     * @return array<string, mixed>
     */
    private function accepted(Transaction $transaction, string $status): array
    {
        return [
            'client_uuid' => $transaction->client_uuid,
            'status' => $status,
            'transaction_id' => $transaction->id,
            'receipt_no' => $transaction->receipt_no,
            'receipt_url' => route('field.receipts.show', $transaction, false),
            'amount_label' => $transaction->amount->format(),
        ];
    }

    /**
     * Permanent for this payload; logged so the office can follow up on cash the officer still holds.
     *
     * @param  array<string, mixed>  $errors
     * @return array<string, mixed>
     */
    private function rejected(string $uuid, string $code, string $message, User $officer, array $errors = []): array
    {
        Log::warning('Field collection sync rejected', ['officer_id' => $officer->id, 'client_uuid' => $uuid, 'code' => $code]);

        return ['client_uuid' => $uuid, 'status' => 'rejected', 'code' => $code, 'message' => $message, 'errors' => $errors];
    }
}
