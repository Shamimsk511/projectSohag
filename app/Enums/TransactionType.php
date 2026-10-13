<?php

namespace App\Enums;

/**
 * Ledger row types from docs/DOMAIN_RULES.md. Amounts are always positive; the type gives direction.
 */
enum TransactionType: string
{
    case LoanDisbursement = 'loan_disbursement';
    case LoanCollection = 'loan_collection';
    case LoanOpeningDisbursement = 'loan_opening_disbursement';
    case LoanOpeningReceived = 'loan_opening_received';
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
     * Types record() accepts. Adjustment rows have no defined direction yet;
     * reversals are written only by reverse().
     */
    public function isRecordable(): bool
    {
        return match ($this) {
            self::Adjustment, self::Reversal => false,
            default => true,
        };
    }

    /**
     * Account type the row must post to; null for office entries (they may still link any account).
     */
    public function accountType(): ?AccountType
    {
        return match ($this) {
            self::LoanDisbursement, self::LoanCollection, self::LoanOpeningDisbursement, self::LoanOpeningReceived => AccountType::Loan,
            self::SavingDeposit, self::SavingWithdrawal, self::SavingOpeningBalance, self::SavingMaturityCredit => AccountType::Saving,
            self::InvestmentDeposit, self::InvestmentReturn => AccountType::Fdr,
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
     * Money received from a party that the field collects and the office receipts: installments,
     * savings deposits and FDR deposits.
     *
     * @return list<self>
     */
    public static function collectionTypes(): array
    {
        return [self::LoanCollection, self::SavingDeposit, self::InvestmentDeposit];
    }

    /**
     * Money the office hands to a member: a loan given, a saving withdrawal, an FDR payback.
     *
     * @return list<self>
     */
    public static function payoutTypes(): array
    {
        return [self::LoanDisbursement, self::SavingWithdrawal, self::InvestmentReturn];
    }

    /**
     * Book entries with no money changing hands get no payment method.
     */
    public function movesMoney(): bool
    {
        return ! in_array($this, [self::SavingOpeningBalance, self::SavingMaturityCredit, ...self::carriedInLoanTypes()], true);
    }

    /**
     * A loan that existed before go-live: what was handed over and what was received, as book
     * entries (no cash, no receipt). Posted only when the loan account is opened.
     *
     * @return list<self>
     */
    public static function carriedInLoanTypes(): array
    {
        return [self::LoanOpeningDisbursement, self::LoanOpeningReceived];
    }

    public function isCarriedInLoan(): bool
    {
        return in_array($this, self::carriedInLoanTypes(), true);
    }

    /**
     * Rows that carry pre-go-live history. Their dates can be long past, so they say nothing about
     * when the system started moving money.
     *
     * @return list<self>
     */
    public static function openingTypes(): array
    {
        return [self::SavingOpeningBalance, ...self::carriedInLoanTypes()];
    }

    /**
     * Office income and expense: posted from the office entries screen, or income from a client on its account page.
     *
     * @return list<self>
     */
    public static function officeTypes(): array
    {
        return [self::OfficeIncome, self::OfficeExpense];
    }

    public function isOffice(): bool
    {
        return in_array($this, self::officeTypes(), true);
    }

    /**
     * Money received from a party gets a receipt number.
     */
    public function issuesReceipt(): bool
    {
        return in_array($this, self::collectionTypes(), true);
    }
}
