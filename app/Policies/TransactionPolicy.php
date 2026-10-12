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

    public function reverse(User $user, Transaction $transaction): bool
    {
        return $user->can(Permission::ReverseTransactions->value)
            && ($transaction->account === null || $user->can('view', $transaction->account));
    }
}
