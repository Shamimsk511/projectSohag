<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

/**
 * Who may post ledger entries from the office screens and who may reverse them. Domain rules
 * (balances, dates, closed accounts) stay in TransactionService. No update/delete: rows are immutable.
 */
class TransactionPolicy
{
    /**
     * Post one of the account's entry types (disbursement, deposit, withdrawal) on $account.
     */
    public function create(User $user, Account $account): bool
    {
        return $user->can(Permission::PostLedgerEntries->value) && $user->can('view', $account);
    }

    /**
     * See the office income/expense list.
     */
    public function viewOffice(User $user): bool
    {
        return $user->can(Permission::PostOfficeEntries->value) || $user->can(Permission::ViewAllReports->value);
    }

    /**
     * Post office income or expense (no account).
     */
    public function createOffice(User $user): bool
    {
        return $user->can(Permission::PostOfficeEntries->value);
    }

    /**
     * Post office income taken from the account's party (fee, form...), linked to the account.
     */
    public function createOfficeIncome(User $user, Account $account): bool
    {
        return $user->can(Permission::PostOfficeEntries->value) && $user->can('view', $account);
    }

    public function reverse(User $user, Transaction $transaction): bool
    {
        return $user->can(Permission::ReverseTransactions->value)
            && ($transaction->account === null || $user->can('view', $transaction->account));
    }

    /**
     * Open the printable receipt: the officer who collected it, or staff who can see its account.
     */
    public function viewReceipt(User $user, Transaction $transaction): bool
    {
        return $transaction->receipt_no !== null
            && ($transaction->field_officer_id === $user->id
                || ($transaction->account !== null && $user->can('view', $transaction->account)));
    }
}
