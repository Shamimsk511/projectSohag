<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A TransactionService rule failure. Still a ValidationException (screens show it under the field),
 * plus the rule key (`app.transactions.<rule>`) so the PWA sync can report a stable code.
 */
class LedgerRuleViolation extends ValidationException
{
    public string $rule = '';

    public static function of(string $field, string $rule): self
    {
        $exception = static::withMessages([$field => __('app.transactions.'.$rule)]);
        $exception->rule = $rule;

        return $exception;
    }
}
