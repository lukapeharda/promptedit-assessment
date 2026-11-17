<?php

namespace App\Pixelforge;

use InvalidArgumentException;

/**
 * Money is stored and computed as integer nanodollars (1 USD = 1_000_000_000).
 *
 * Pixelforge reports every amount as an exact decimal string with nine
 * decimal places, so nanodollars represent its costs without any rounding.
 * Floats are never used for money anywhere in the app.
 */
final class Money
{
    public const SCALE = 9;

    public const NANOS_PER_DOLLAR = 1_000_000_000;

    /** User price = provider cost + 25% markup, as an exact fraction. */
    public const MARKUP_NUMERATOR = 125;

    public const MARKUP_DENOMINATOR = 100;

    /**
     * "0.046125000" -> 46125000. Rejects values with more than nine decimals
     * instead of silently truncating them.
     */
    public static function fromDecimal(string $usd): int
    {
        $usd = trim($usd);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $usd)) {
            throw new InvalidArgumentException("Not a decimal amount: [{$usd}]");
        }

        $nanos = bcmul($usd, (string) self::NANOS_PER_DOLLAR, self::SCALE);

        if (bccomp($nanos, bcadd($nanos, '0', 0), self::SCALE) !== 0) {
            throw new InvalidArgumentException("Amount [{$usd}] is more precise than a nanodollar.");
        }

        return (int) bcadd($nanos, '0', 0);
    }

    /** 46125000 -> "0.046125000" */
    public static function toDecimal(int $nanos): string
    {
        return bcdiv((string) $nanos, (string) self::NANOS_PER_DOLLAR, self::SCALE);
    }

    /**
     * Human-friendly dollars: at least two decimals, at most six, trailing
     * zeros trimmed. Sub-microdollar remainders are rounded up so a displayed
     * price is never lower than what is charged. 46125000 -> "$0.046125".
     */
    public static function format(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $abs = abs($nanos);
        $micros = intdiv($abs + 999, 1000);
        $decimal = bcdiv((string) $micros, '1000000', 6);
        [$whole, $fraction] = explode('.', $decimal);
        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, 2, '0');

        return "{$sign}\${$whole}.{$fraction}";
    }

    /** Provider cost -> what the user pays, rounded up to a whole nanodollar. */
    public static function withMarkup(int $providerCostNanos): int
    {
        $scaled = $providerCostNanos * self::MARKUP_NUMERATOR;

        return intdiv($scaled + self::MARKUP_DENOMINATOR - 1, self::MARKUP_DENOMINATOR);
    }
}
