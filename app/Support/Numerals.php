<?php

namespace App\Support;

/**
 * Converts between ASCII and Bengali digits for display and input.
 */
final class Numerals
{
    private const ASCII = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    private const BENGALI = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    /**
     * Render digits for the given locale (defaults to the app locale).
     */
    public static function localize(string $value, ?string $locale = null): string
    {
        if (($locale ?? app()->getLocale()) !== 'bn') {
            return $value;
        }

        return str_replace(self::ASCII, self::BENGALI, $value);
    }

    /**
     * Normalise user input that may contain Bengali digits.
     */
    public static function toAscii(string $value): string
    {
        return str_replace(self::BENGALI, self::ASCII, $value);
    }
}
