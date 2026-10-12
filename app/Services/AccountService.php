<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\AccountAdjustment;
use App\Models\Party;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Only writer of accounts and account_adjustments. Domain rules that need the database live here;
 * Form Requests check input shape. Rule failures are thrown as ValidationException.
 */
class AccountService
{
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Open an account with the next SV-/LN- number. Loans need a contract amount; savings have none.
     */
    public function open(Party $party, AccountType $type, ?Money $contractAmount, Carbon $openedAt, User $actor): Account
    {
        if (! $party->isActive()) {
            throw ValidationException::withMessages(['account_type' => __('app.accounts.party_inactive')]);
        }

        return DB::transaction(function () use ($party, $type, $contractAmount, $openedAt, $actor): Account {
            $account = Account::create([
                'party_id' => $party->id,
                'account_number' => sprintf('%s-%06d', $type->prefix(), $this->sequences->next('account.'.$type->prefix())),
                'account_type' => $type,
                'contract_amount' => $type === AccountType::Loan ? $contractAmount : null,
                'opened_at' => $openedAt->toDateString(),
                'created_by' => $actor->id,
            ]);

            $this->audit->created('account.opened', $account, $actor);

            return $account;
        });
    }

    /**
     * Change a loan's contract amount, keeping the old value, reason and actor in account_adjustments.
     */
    public function adjustContractAmount(Account $account, Money $newAmount, string $reason, User $actor): AccountAdjustment
    {
        return DB::transaction(function () use ($account, $newAmount, $reason, $actor): AccountAdjustment {
            $account = $this->lock($account);
            $oldAmount = $account->contract_amount;

            if (! $account->isLoan() || ! $account->isActive() || $oldAmount === null) {
                throw ValidationException::withMessages(['contract_amount' => __('app.accounts.not_adjustable')]);
            }

            if ($oldAmount->equals($newAmount)) {
                throw ValidationException::withMessages(['contract_amount' => __('app.accounts.amount_unchanged')]);
            }

            $account->update(['contract_amount' => $newAmount]);
            $this->audit->updated('account.contract_adjusted', $account, $actor, extraAfter: ['reason' => $reason]);

            return $account->adjustments()->create([
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * Close an account today. A non-zero loan outstanding or saving balance needs the override
     * permission (admin) and a reason.
     */
    public function close(Account $account, ?string $reason, User $actor): Account
    {
        return DB::transaction(function () use ($account, $reason, $actor): Account {
            $account = $this->lock($account);

            if (! $account->isActive()) {
                throw ValidationException::withMessages(['close_reason' => __('app.accounts.already_closed')]);
            }

            if (! $this->ledger->remainingBalance($account)->isZero()) {
                if (! $actor->can(Permission::OverrideLimits->value)) {
                    throw ValidationException::withMessages(['close_reason' => __('app.accounts.balance_not_zero')]);
                }

                if ($reason === null || trim($reason) === '') {
                    throw ValidationException::withMessages(['close_reason' => __('app.accounts.override_reason_required')]);
                }
            }

            $account->update([
                'closed_at' => today(config('app.display_timezone'))->toDateString(),
                'closed_by' => $actor->id,
                'close_reason' => $reason,
            ]);
            $this->audit->updated('account.closed', $account, $actor);

            return $account;
        });
    }

    /**
     * Fresh copy of the account with its row locked until the surrounding transaction ends.
     */
    private function lock(Account $account): Account
    {
        return Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
    }
}
