<?php

namespace App\Enums;

enum ScheduleStatus: string
{
    case Pending = 'pending';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Missed = 'missed';
    case Cancelled = 'cancelled';
}
