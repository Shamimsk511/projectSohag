<?php

namespace App\Enums;

/**
 * How often a collection plan expects money. Custom dates are entered as schedule rows by hand.
 */
enum PlanFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
}
