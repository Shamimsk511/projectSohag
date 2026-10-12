<?php

namespace App\Enums;

/**
 * Status for records that are never deleted (areas, parties): inactive ones stay for history.
 */
enum ActiveStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
