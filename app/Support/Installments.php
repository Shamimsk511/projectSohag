<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Plain division of an amount to collect into installments: the installment typed by the office, or
 * the total over a number of installments rounded up to whole taka. The last installment is what is
 * left. No rate, nothing added.
 */
final readonly class Installments
{
    private function __construct(
        public Money $total,
        public Money $installment,
        public int $count,
    ) {}

    public static function ofAmount(Money $total, Money $installment): self
    {
        if (! $total->isPositive() || ! $installment->isPositive()) {
            throw new InvalidArgumentException('Total and installment must be positive.');
        }

        return new self($total, $installment, intdiv($total->poisha + $installment->poisha - 1, $installment->poisha));
    }

    /**
     * Rounding up to whole taka may need fewer installments than asked for; `count` is the real number.
     */
    public static function ofCount(Money $total, int $count): self
    {
        if ($count < 1) {
            throw new InvalidArgumentException('Count must be at least one.');
        }

        $step = $count * 100;

        return self::ofAmount($total, Money::fromPoisha(intdiv($total->poisha + $step - 1, $step) * 100));
    }

    public function last(): Money
    {
        return $this->total->minus(Money::fromPoisha($this->installment->poisha * ($this->count - 1)));
    }
}
