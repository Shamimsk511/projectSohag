<?php

namespace App\Enums;

enum AccountType: string
{
    case Saving = 'saving';
    case Loan = 'loan';

    /**
     * Account number prefix: SV-000001, LN-000001.
     */
    public function prefix(): string
    {
        return match ($this) {
            self::Saving => 'SV',
            self::Loan => 'LN',
        };
    }
}
