<?php

namespace App\Enums;

enum AccountType: string
{
    case Saving = 'saving';
    case Loan = 'loan';
    case Fdr = 'fdr';

    /**
     * Account number prefix: SV-000001, LN-000001.
     */
    public function prefix(): string
    {
        return match ($this) {
            self::Saving => 'SV',
            self::Loan => 'LN',
            self::Fdr => 'FD',
        };
    }

    /**
     * Entry types office staff post on this account from the account page. Loan collections come
     * through the collection flow (Phase 4), not here.
     *
     * @return list<TransactionType>
     */
    public function entryTypes(): array
    {
        return match ($this) {
            self::Saving => [
                TransactionType::SavingDeposit,
                TransactionType::SavingWithdrawal,
                TransactionType::SavingOpeningBalance,
                TransactionType::SavingMaturityCredit,
            ],
            self::Loan => [TransactionType::LoanDisbursement, TransactionType::LoanCollection],
            self::Fdr => [TransactionType::InvestmentDeposit, TransactionType::InvestmentReturn],
        };
    }

    /**
     * What a scheduled collection on this account records: an installment or a savings deposit.
     */
    public function collectionType(): TransactionType
    {
        return match ($this) {
            self::Saving => TransactionType::SavingDeposit,
            self::Loan => TransactionType::LoanCollection,
            self::Fdr => TransactionType::InvestmentDeposit,
        };
    }
}
