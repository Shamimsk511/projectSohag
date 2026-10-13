<?php

namespace App\Enums;

/**
 * Permissions granted on top of role checks. Admin passes every check via Gate::before,
 * so admin is never listed here. Mapping follows the Visibility section of docs/DOMAIN_RULES.md.
 */
enum Permission: string
{
    case ManageUsers = 'users.manage';
    case ViewAllParties = 'parties.view_all';
    case ManageParties = 'parties.manage';
    case AssignParties = 'parties.assign';
    case ManageAccounts = 'accounts.manage';
    case ManageSchedules = 'schedules.manage';
    case RecordCollections = 'collections.record';
    case PostLedgerEntries = 'ledger.post';
    case PostOfficeEntries = 'office_entries.post';
    case ReverseTransactions = 'transactions.reverse';
    case OverrideLimits = 'limits.override';
    case ViewAllReports = 'reports.view_all';
    case ReceiveCash = 'cash.receive';
    case CloseDays = 'days.close';
    case PostOnClosedDays = 'closed_days.post';
    case ManageSettings = 'settings.manage';
    case SetOpeningBalances = 'opening_balances.set';

    /**
     * Non-admin roles that hold this permission.
     *
     * @return list<Role>
     */
    public function roles(): array
    {
        return match ($this) {
            self::ManageUsers, self::OverrideLimits, self::PostOnClosedDays, self::ManageSettings, self::SetOpeningBalances => [],
            self::ViewAllParties => [Role::Manager, Role::Accountant],
            self::ManageParties, self::AssignParties, self::ManageAccounts, self::ManageSchedules,
            self::ReverseTransactions => [Role::Manager],
            self::RecordCollections => [Role::Manager, Role::FieldOfficer],
            self::PostLedgerEntries => [Role::Manager, Role::Accountant],
            self::PostOfficeEntries => [Role::Accountant],
            self::ViewAllReports, self::ReceiveCash, self::CloseDays => [Role::Manager, Role::Accountant],
        };
    }
}
