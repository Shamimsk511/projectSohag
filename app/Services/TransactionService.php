<?php

namespace App\Services;

use App\Enums\Permission;
use App\Enums\ScheduleStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\CollectionSchedule;
use App\Models\DailyClosing;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TransactionData;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Only writer of transactions. Enforces the money movement rules of docs/DOMAIN_RULES.md; rule
 * failures are thrown as ValidationException. Who may post which type is checked by policies.
 */
class TransactionService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly SequenceService $sequences,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Record a new ledger row. A repeated client_uuid returns the row already recorded for it
     * (wasRecentlyCreated is false), so offline retries never double-post.
     */
    public function record(TransactionData $data, User $actor): Transaction
    {
        $existing = $this->findByClientUuid($data->clientUuid);
        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(fn (): Transaction => $this->create($data, $actor));
        } catch (UniqueConstraintViolationException $exception) {
            // A retry with the same client_uuid committed first.
            return $this->findByClientUuid($data->clientUuid) ?? throw $exception;
        }
    }

    /**
     * Cancel $original with a reversal row dated today. The original is never touched.
     */
    public function reverse(Transaction $original, string $reason, User $actor): Transaction
    {
        if (! $actor->can(Permission::ReverseTransactions->value)) {
            $this->reject('reason', 'reverse_forbidden');
        }

        if (trim($reason) === '') {
            $this->reject('reason', 'reason_required');
        }

        return DB::transaction(function () use ($original, $reason, $actor): Transaction {
            $account = $original->account_id ? $this->lockAccount($original->account_id) : null;
            $original = Transaction::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();

            if ($original->type === TransactionType::Reversal) {
                $this->reject('reason', 'not_reversible');
            }

            if (Transaction::query()->where('reverses_transaction_id', $original->id)->exists()) {
                $this->reject('reason', 'already_reversed');
            }

            if ($account && ! $account->isActive()) {
                $this->reject('reason', 'account_closed');
            }

            if ($this->isLocked($original->transaction_date, $actor) || $this->isLocked(today(config('app.display_timezone')), $actor)) {
                $this->reject('reason', 'day_closed');
            }

            if ($account && $original->type === TransactionType::SavingDeposit
                && $original->amount->greaterThan($this->ledger->savingBalance($account))) {
                $this->reject('reason', 'reversal_exceeds_balance');
            }

            $reversal = Transaction::create([
                'account_id' => $original->account_id,
                'party_id' => $original->party_id,
                'field_officer_id' => $original->field_officer_id,
                'schedule_id' => $original->schedule_id,
                'type' => TransactionType::Reversal,
                'amount' => $original->amount,
                'payment_method' => $original->payment_method,
                'description' => trim($reason),
                'transaction_date' => today(config('app.display_timezone'))->toDateString(),
                'reverses_transaction_id' => $original->id,
                'created_by' => $actor->id,
            ]);

            $this->audit->created('transaction.reversed', $reversal, $actor);

            if ($original->schedule_id !== null) {
                $this->settle($this->lockSchedule($original->schedule_id), $actor);
            }

            return $reversal;
        });
    }

    private function create(TransactionData $data, User $actor): Transaction
    {
        $type = $data->type;
        $account = $data->account ? $this->lockAccount($data->account->id) : null;
        $date = $data->date->toDateString();

        if (! $type->isRecordable()) {
            $this->reject('type', 'unsupported_type');
        }

        if ($type->accountType() !== $account?->account_type) {
            $this->reject('account_id', 'account_mismatch');
        }

        $schedule = $data->schedule ? $this->lockSchedule($data->schedule->id) : null;

        if ($schedule && ($schedule->account_id !== $account?->id || $type !== $account->account_type->collectionType())) {
            $this->reject('schedule_id', 'schedule_mismatch');
        }

        if ($schedule && ! $schedule->status->isCollectable()) {
            $this->reject('schedule_id', 'schedule_not_open');
        }

        if (! $data->amount->isPositive()) {
            $this->reject('amount', 'amount_not_positive');
        }

        if ($date > today(config('app.display_timezone'))->toDateString()) {
            $this->reject('transaction_date', 'future_date');
        }

        if ($this->isLocked($data->date, $actor)) {
            $this->reject('transaction_date', 'day_closed');
        }

        if ($account && ! $account->isActive()) {
            $this->reject('account_id', 'account_closed');
        }

        if ($account && $date < $account->opened_at->toDateString()) {
            $this->reject('transaction_date', 'before_opened');
        }

        if ($account && $type === TransactionType::SavingWithdrawal
            && $data->amount->greaterThan($this->ledger->savingBalance($account))) {
            $this->reject('amount', 'exceeds_balance');
        }

        $overridden = $account && $type === TransactionType::LoanCollection
            && $data->amount->greaterThan($this->ledger->loanOutstanding($account));

        if ($overridden) {
            if (! $actor->can(Permission::OverrideLimits->value)) {
                $this->reject('amount', 'exceeds_outstanding');
            }

            if (trim((string) $data->overrideReason) === '') {
                $this->reject('override_reason', 'override_reason_required');
            }
        }

        $transaction = Transaction::create([
            'account_id' => $account?->id,
            'party_id' => $account?->party_id,
            'field_officer_id' => $data->fieldOfficer?->id,
            'schedule_id' => $schedule?->id,
            'type' => $type,
            'amount' => $data->amount,
            'payment_method' => $data->paymentMethod,
            'reference' => $data->reference,
            'receipt_no' => $type->issuesReceipt() ? $this->receiptNumber($data->date->format('Ym')) : null,
            'description' => $data->description,
            'override_reason' => $overridden ? trim((string) $data->overrideReason) : null,
            'transaction_date' => $date,
            'client_uuid' => $data->clientUuid,
            'created_by' => $actor->id,
            'approved_by' => $overridden ? $actor->id : null,
        ]);

        $this->audit->created('transaction.recorded', $transaction, $actor);

        if ($schedule) {
            $this->settle($schedule, $actor);
        }

        return $transaction;
    }

    /**
     * Set a schedule row's status from what the ledger holds against it. With nothing collected a
     * row is missed once its day has passed, pending otherwise; cancelled rows stay cancelled.
     */
    private function settle(CollectionSchedule $schedule, User $actor): void
    {
        $collected = $this->ledger->collectedForSchedules([$schedule->id])[$schedule->id];
        $isPastDue = $schedule->due_date->toDateString() < today(config('app.display_timezone'))->toDateString();

        $status = match (true) {
            $schedule->status === ScheduleStatus::Cancelled => ScheduleStatus::Cancelled,
            ! $collected->isPositive() => $isPastDue ? ScheduleStatus::Missed : ScheduleStatus::Pending,
            $schedule->expected_amount->greaterThan($collected) => ScheduleStatus::PartiallyPaid,
            default => ScheduleStatus::Paid,
        };

        $schedule->update(['status' => $status]);
        $this->audit->updated('schedule.status_changed', $schedule, $actor);
    }

    /**
     * R-YYYYMM-00001, numbered per month of the transaction date.
     */
    private function receiptNumber(string $yearMonth): string
    {
        return sprintf('R-%s-%05d', $yearMonth, $this->sequences->next('receipt.'.$yearMonth));
    }

    /**
     * A closed day takes no new rows except from those allowed to post on closed days (admin).
     */
    private function isLocked(Carbon $date, User $actor): bool
    {
        return ! $actor->can(Permission::PostOnClosedDays->value) && DailyClosing::isClosed($date);
    }

    private function findByClientUuid(?string $clientUuid): ?Transaction
    {
        return $clientUuid === null ? null : Transaction::query()->where('client_uuid', $clientUuid)->first();
    }

    /**
     * Fresh copy of the account, locked until the surrounding transaction ends so balance checks
     * cannot race another write.
     */
    private function lockAccount(int $accountId): Account
    {
        return Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
    }

    private function lockSchedule(int $scheduleId): CollectionSchedule
    {
        return CollectionSchedule::query()->whereKey($scheduleId)->lockForUpdate()->firstOrFail();
    }

    private function reject(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __('app.transactions.'.$key)]);
    }
}
