<?php

namespace App\Support;

use App\Models\Transaction;

/**
 * One ledger row on an account statement. A reversal carries a negative amount in its original's column.
 */
final readonly class StatementLine
{
    public function __construct(
        public Transaction $transaction,
        public ?Money $in,
        public ?Money $out,
        public Money $running,
        public bool $reversed,
    ) {}
}
