<?php

namespace App\Enums;

/**
 * Ledger row types from docs/DOMAIN_RULES.md. Amounts are always positive; the type gives direction.
 */
enum TransactionType: string
{
    case LoanDisbursement = 'loan_disbursement';
    case LoanCollection = 'loan_collection';
    case SavingDeposit = 'saving_deposit';
    case SavingWithdrawal = 'saving_withdrawal';
    case SavingOpeningBalance = 'saving_opening_balance';
    case SavingMaturityCredit = 'saving_maturity_credit';
    case InvestmentDeposit = 'investment_deposit';
    case InvestmentReturn = 'investment_return';
    case OfficeIncome = 'office_income';
    case OfficeExpense = 'office_expense';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';

    /**
     * Types record() accepts. Investment and adjustment rows have no defined direction yet;
     * reversals are written only by reverse().
     */
    public function isRecordable(): bool
    {
        return match ($this) {
            self::InvestmentDeposit, self::InvestmentReturn, self::Adjustment, self::Reversal => false,
            default => true,
        };
    }

    /**
     * Account type the row must post to; null for office entries (no account).
     */
    public function accountType(): ?AccountType
    {
        return match ($this) {
            self::LoanDisbursement, self::LoanCollection => AccountType::Loan,
            self::SavingDeposit, self::SavingWithdrawal, self::SavingOpeningBalance, self::SavingMaturityCredit => AccountType::Saving,
            default => null,
        };
    }

    /**
     * Rows that add to a saving balance: deposits, the amount saved before the system and the credit
     * that brings the account up to its agreed amount.
     *
     * @return list<self>
     */
    public static function savingInTypes(): array
    {
        return [self::SavingDeposit, self::SavingOpeningBalance, self::SavingMaturityCredit];
    }

    /**
     * Book entries with no money changing hands get no payment method.
     */
    public function movesMoney(): bool
    {
        return ! in_array($this, [self::SavingOpeningBalance, self::SavingMaturityCredit], true);
    }

    /**
     * Office income and expense: posted without an account from the office entries screen.
     *
     * @return list<self>
     */
    public static function officeTypes(): array
    {
        return [self::OfficeIncome, self::OfficeExpense];
    }

    /**
     * Money received from a party gets a receipt number.
     */
    public function issuesReceipt(): bool
    {
        return in_array($this, [self::LoanCollection, self::SavingDeposit], true);
    }
}
