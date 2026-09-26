<?php

namespace App\Support;

/**
 * Currency helpers.
 *
 * Every calculation in the POS runs on integer cents. Binary floats cannot
 * represent most decimal fractions exactly, so adding 0.10 to 0.20 in a float
 * gives 0.30000000000000004, and a till that is a rounding error away from the
 * book is a till nobody trusts. Cents are converted to a decimal string only
 * at the edges: when reading input, and when writing to the database.
 */
final class Money
{
    /**
     * Convert a user- or database-supplied amount into whole cents.
     */
    public static function toCents(float|int|string|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return (int) round(((float) $amount) * 100);
    }

    /**
     * Convert cents into the plain decimal string the decimal columns expect.
     */
    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Render an amount for display, e.g. 12.5 becomes "12.50".
     */
    public static function format(float|int|string|null $amount, ?string $symbol = null): string
    {
        $value = self::fromCents(self::toCents($amount));

        return $symbol === null ? $value : $symbol.$value;
    }

    /**
     * Apply a percentage to a cent amount, rounding half-up to the nearest cent.
     *
     * Rounding happens once, here, so the sum of the rounded lines is the
     * figure the customer is actually charged.
     */
    public static function percentOf(int $cents, float $percent): int
    {
        return (int) round($cents * ($percent / 100));
    }

    /**
     * Clamp a discount so it can never exceed the amount being discounted.
     */
    public static function cap(int $discount, int $amount): int
    {
        return max(0, min($discount, $amount));
    }
}
