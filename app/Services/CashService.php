<?php

namespace App\Services;

use App\Enums\CashHandoverMode;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\CashHandover;
use App\Models\DailyClosing;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash handling from docs/DOMAIN_RULES.md: officers hand collected cash to the office, and closed
 * days lock their dates for non-admins. Neither writes the ledger. In automatic handover mode each
 * officer cash collection is handed over as it is recorded.
 */
class CashService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
        private readonly SettingService $settings,
    ) {}

    /**
     * Record cash the office received from a field officer. The officer row is locked so two
     * handovers cannot both pass the cash-in-hand check.
     */
    public function recordHandover(User $officer, Money $amount, Carbon $date, ?string $note, User $actor): CashHandover
    {
        if (! $officer->hasRole(Role::FieldOfficer->value)) {
            $this->reject('field_officer_id', 'cash.not_field_officer');
        }

        if (! $amount->isPositive()) {
            $this->reject('amount', 'cash.amount_not_positive');
        }

        if ($date->toDateString() > $this->today()->toDateString()) {
            $this->reject('handover_date', 'cash.future_date');
        }

        if (! $actor->can(Permission::PostOnClosedDays->value) && DailyClosing::isClosed($date)) {
            $this->reject('handover_date', 'cash.day_closed');
        }

        return DB::transaction(function () use ($officer, $amount, $date, $note, $actor): CashHandover {
            User::query()->whereKey($officer->id)->lockForUpdate()->firstOrFail();

            $inHand = $this->ledger->cashInHand([$officer->id])[$officer->id];
            if ($amount->greaterThan($inHand)) {
                $this->reject('amount', 'cash.exceeds_in_hand', ['amount' => $inHand->format()]);
            }

            $handover = CashHandover::create([
                'field_officer_id' => $officer->id,
                'amount' => $amount,
                'handover_date' => $date->toDateString(),
                'note' => $note,
                'confirmed_by' => $actor->id,
            ]);

            $this->audit->created('cash.handed_over', $handover, $actor);

            return $handover;
        });
    }

    /**
     * In automatic mode, hand a field officer's cash collection to the office at once. Runs inside
     * the caller's DB transaction right after the row is recorded; rules were checked there.
     */
    public function handOverCollection(Transaction $collection, User $actor): void
    {
        $isOfficerCash = $collection->field_officer_id !== null
            && $collection->payment_method === PaymentMethod::Cash
            && in_array($collection->type, [TransactionType::LoanCollection, TransactionType::SavingDeposit], true);

        if (! $isOfficerCash || $this->settings->cashHandoverMode() !== CashHandoverMode::Automatic) {
            return;
        }

        $handover = CashHandover::create([
            'field_officer_id' => $collection->field_officer_id,
            'transaction_id' => $collection->id,
            'amount' => $collection->amount,
            'handover_date' => $collection->transaction_date->toDateString(),
            'confirmed_by' => $actor->id,
        ]);

        $this->audit->created('cash.handed_over', $handover, $actor);
    }

    /**
     * Close a business date (today or earlier). There is no reopen.
     */
    public function closeDay(Carbon $date, User $actor): DailyClosing
    {
        if ($date->toDateString() > $this->today()->toDateString()) {
            $this->reject('date', 'closings.future_date');
        }

        if (DailyClosing::isClosed($date)) {
            $this->reject('date', 'closings.already_closed');
        }

        try {
            return DB::transaction(function () use ($date, $actor): DailyClosing {
                $closing = DailyClosing::create(['date' => $date->toDateString(), 'closed_by' => $actor->id]);
                $this->audit->created('day.closed', $closing, $actor);

                return $closing;
            });
        } catch (UniqueConstraintViolationException) {
            $this->reject('date', 'closings.already_closed');
        }
    }

    /**
     * Automatic closing: close every open date up to the day before yesterday, from the first ledger
     * date (or just that day when there is no history). Yesterday stays open because offline sync
     * still accepts collections dated on it today. Returns how many days were closed.
     */
    public function closeDueDays(): int
    {
        $last = Carbon::parse($this->today()->subDays(2)->toDateString());
        $first = Transaction::query()->min('transaction_date');
        $from = $first === null ? $last->copy() : Carbon::parse($first)->startOfDay();
        if ($from->greaterThan($last)) {
            return 0;
        }

        $closed = DailyClosing::query()
            ->where('date', '>=', $from->toDateString())
            ->where('date', '<', $last->copy()->addDay()->toDateString())
            ->pluck('date')
            ->map(fn (mixed $date): string => Carbon::parse($date)->toDateString())
            ->flip();

        $count = 0;
        for ($date = $from->copy(); $date->lessThanOrEqualTo($last); $date->addDay()) {
            if ($closed->has($date->toDateString())) {
                continue;
            }

            try {
                DB::transaction(function () use ($date): void {
                    $closing = DailyClosing::create(['date' => $date->toDateString(), 'closed_by' => null]);
                    $this->audit->created('day.closed', $closing, null);
                });
                $count++;
            } catch (UniqueConstraintViolationException) {
                // Closed by staff in the meantime.
            }
        }

        return $count;
    }

    private function today(): Carbon
    {
        return today(config('app.display_timezone'));
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function reject(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => __('app.'.$key, $replace)]);
    }
}
