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
    case InvestmentDeposit = 'investment_deposit';
    case InvestmentReturn = 'investment_return';
    case OfficeIncome = 'office_income';
    case OfficeExpense = 'office_expense';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';
}
