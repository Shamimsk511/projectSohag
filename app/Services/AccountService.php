<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountAdjustment;
use App\Models\Party;
use App\Models\User;
use App\Support\Money;
use App\Support\TransactionData;
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
        private readonly TransactionService $transactions,
    ) {}

    /**
     * Open a saving account with the next SV- number; `$agreedAmount` is the optional amount agreed
     * for the end of the term.
     */
    public function openSaving(Party $party, ?Money $agreedAmount, Carbon $openedAt, User $actor): Account
    {
        return DB::transaction(fn (): Account => $this->create($party, AccountType::Saving, $agreedAmount, $openedAt, $actor));
    }

    /**
     * Open an FDR account with the next FD- number; `$agreedPayback` is the optional amount the
     * office agreed to pay back (set later through an adjustment when unknown now).
     */
    public function openFdr(Party $party, ?Money $agreedPayback, Carbon $openedAt, User $actor): Account
    {
        return DB::transaction(fn (): Account => $this->create($party, AccountType::Fdr, $agreedPayback, $openedAt, $actor));
    }

    /**
     * Open a loan with the next LN- number and hand over the principal in the same step. The contract
     * amount is principal plus the agreed extra, both typed by the office; nothing is derived. A
     * rejected disbursement (closed day, ...) rolls the account back too.
     */
    public function openLoan(Party $party, Money $principal, Money $agreedExtra, PaymentMethod $paymentMethod, Carbon $openedAt, User $actor): Account
    {
        return DB::transaction(function () use ($party, $principal, $agreedExtra, $paymentMethod, $openedAt, $actor): Account {
            $account = $this->create($party, AccountType::Loan, $principal->plus($agreedExtra), $openedAt, $actor);

            $this->transactions->record(new TransactionData(
                type: TransactionType::LoanDisbursement,
                amount: $principal,
                date: $openedAt,
                account: $account,
                paymentMethod: $paymentMethod,
            ), $actor);

            return $account;
        });
    }

    /**
     * Open a loan that existed before go-live. The account starts on its original date with the
     * principal as a book entry; what was already received is a second book entry dated `$asOf`.
     * No cash moves and no receipt is issued. Only those who may set opening balances.
     */
    public function openExistingLoan(Party $party, Money $principal, Money $agreedExtra, Money $alreadyReceived, Carbon $openedAt, Carbon $asOf, User $actor): Account
    {
        if (! $actor->can(Permission::SetOpeningBalances->value)) {
            throw ValidationException::withMessages(['existing_loan' => __('app.transactions.opening_forbidden')]);
        }

        if ($asOf->lt($openedAt) || $asOf->gt(today(config('app.display_timezone')))) {
            throw ValidationException::withMessages(['carried_as_of' => __('app.accounts.carried_as_of_range')]);
        }

        return DB::transaction(function () use ($party, $principal, $agreedExtra, $alreadyReceived, $openedAt, $asOf, $actor): Account {
            $account = $this->create($party, AccountType::Loan, $principal->plus($agreedExtra), $openedAt, $actor);

            $this->transactions->record(new TransactionData(
                type: TransactionType::LoanOpeningDisbursement,
                amount: $principal,
                date: $openedAt,
                account: $account,
            ), $actor);

            if ($alreadyReceived->isPositive()) {
                $this->transactions->record(new TransactionData(
                    type: TransactionType::LoanOpeningReceived,
                    amount: $alreadyReceived,
                    date: $asOf,
                    account: $account,
                ), $actor);
            }

            return $account;
        });
    }

    private function create(Party $party, AccountType $type, ?Money $contractAmount, Carbon $openedAt, User $actor): Account
    {
        if (! $party->isActive()) {
            throw ValidationException::withMessages(['account_type' => __('app.accounts.party_inactive')]);
        }

        $account = Account::create([
            'party_id' => $party->id,
            'account_number' => sprintf('%s-%06d', $type->prefix(), $this->sequences->next('account.'.$type->prefix())),
            'account_type' => $type,
            'contract_amount' => $contractAmount,
            'opened_at' => $openedAt->toDateString(),
            'created_by' => $actor->id,
        ]);

        $this->audit->created('account.opened', $account, $actor);

        return $account;
    }

    /**
     * Change a loan's contract amount or set/change a saving's agreed amount or an FDR's agreed payback, keeping the old value,
     * reason and actor in account_adjustments.
     */
    public function adjustContractAmount(Account $account, Money $newAmount, string $reason, User $actor): AccountAdjustment
    {
        return DB::transaction(function () use ($account, $newAmount, $reason, $actor): AccountAdjustment {
            $account = $this->lock($account);
            $oldAmount = $account->contract_amount;

            if (! $account->isActive() || ($account->isLoan() && $oldAmount === null)) {
                throw ValidationException::withMessages(['contract_amount' => __('app.accounts.not_adjustable')]);
            }

            if ($oldAmount?->equals($newAmount)) {
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
     * Close an account today. A non-zero loan outstanding, FDR remaining payback or saving balance needs the override
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
