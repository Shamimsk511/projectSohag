<?php

namespace App\Services;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\CashHandover;
use App\Models\DailyClosing;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash handling from docs/DOMAIN_RULES.md: officers hand collected cash to the office, and closed
 * days lock their dates for non-admins. Neither writes the ledger.
 */
class CashService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
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
