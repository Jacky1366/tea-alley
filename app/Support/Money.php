<?php

namespace App\Support;

/**
 * Money is stored as integer cents. These helpers convert at the edges:
 * admin input (dollars) -> cents on save, and cents -> display strings in views.
 */
final class Money
{
    /**
     * Convert a dollar amount such as "6.25" to cents (625).
     */
    public static function toCents(string|int|float $dollars): int
    {
        return (int) round((float) $dollars * 100);
    }

    /**
     * Convert cents to a plain dollar string for form inputs: 625 -> "6.25".
     */
    public static function toDollars(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Format cents for display: 625 -> "$6.25".
     */
    public static function format(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }
}
