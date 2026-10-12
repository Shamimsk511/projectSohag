<?php

namespace App\Enums;

/**
 * How officers' cash reaches the office. Manual: office staff record each handover. Automatic: every
 * cash collection by an officer is handed over the moment it is recorded.
 */
enum CashHandoverMode: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
}
