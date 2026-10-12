<?php

namespace App\Enums;

/**
 * Staff login status. Users are never deleted (they own ledger history); inactive ones cannot sign in.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
