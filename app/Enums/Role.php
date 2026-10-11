<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Accountant = 'accountant';
    case FieldOfficer = 'field_officer';
}
