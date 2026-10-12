<?php

namespace App\Enums;

enum ScheduleStatus: string
{
    case Pending = 'pending';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Missed = 'missed';
    case Cancelled = 'cancelled';

    /**
     * A collection can still be recorded on the row (missed rows take arrears).
     */
    public function isCollectable(): bool
    {
        return in_array($this, self::collectable(), true);
    }

    /**
     * @return list<self>
     */
    public static function collectable(): array
    {
        return [self::Pending, self::PartiallyPaid, self::Missed];
    }
}
