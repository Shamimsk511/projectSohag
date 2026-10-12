<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Ledger rows of one account for a period. `running` is the saving balance or the loan's total received.
 */
final readonly class AccountStatement
{
    /**
     * @param  list<StatementLine>  $lines
     */
    public function __construct(
        public ?Carbon $from,
        public ?Carbon $to,
        public Money $opening,
        public array $lines,
        public Money $closing,
    ) {}
}
