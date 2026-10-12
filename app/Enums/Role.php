<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Accountant = 'accountant';
    case FieldOfficer = 'field_officer';

    /**
     * Roles that work in the admin panel. Field officers use the /field app instead.
     *
     * @return list<self>
     */
    public static function office(): array
    {
        return [self::Admin, self::Manager, self::Accountant];
    }

    /**
     * Role names for Spatie's role middleware and hasAnyRole().
     *
     * @param  list<self>  $roles
     * @return list<string>
     */
    public static function names(array $roles): array
    {
        return array_map(fn (self $role): string => $role->value, $roles);
    }
}
