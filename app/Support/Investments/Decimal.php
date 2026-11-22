<?php

declare(strict_types=1);

namespace App\Support\Investments;

use InvalidArgumentException;

/**
 * Exact arithmetic on quantities and cents with bcmath, so no share count or amount is ever a float.
 * Quantities are decimal strings with eight places; money is whole cents, rounded half away from zero.
 */
final class Decimal
{
    public const int SCALE = 8;

    private const int WORKING_SCALE = 16;

    public static function of(string|int $value): string
    {
        $text = (string) $value;

        if (preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            throw new InvalidArgumentException('Not a decimal number.');
        }

        return bcadd($text, '0', self::SCALE);
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function isZero(string $value): bool
    {
        return self::compare($value, '0') === 0;
    }

    public static function isNegative(string $value): bool
    {
        return self::compare($value, '0') < 0;
    }

    public static function abs(string $value): string
    {
        return self::isNegative($value) ? bcmul($value, '-1', self::SCALE) : self::of($value);
    }

    public static function negate(string $value): string
    {
        return bcmul($value, '-1', self::SCALE);
    }

    /**
     * The quantity times a price in cents, kept to sixteen places so a later division loses nothing.
     */
    public static function mul(string $quantity, string|int $cents): string
    {
        return bcmul($quantity, (string) $cents, self::WORKING_SCALE);
    }

    /**
     * Whole cents for a decimal amount, rounded half away from zero.
     */
    public static function toCents(string $value): int
    {
        $rounded = self::isNegative($value) ? bcsub($value, '0.5', self::WORKING_SCALE) : bcadd($value, '0.5', self::WORKING_SCALE);

        return (int) bcadd($rounded, '0', 0);
    }

    public static function mulCents(string $quantity, int $cents): int
    {
        return self::toCents(self::mul($quantity, $cents));
    }

    /**
     * Cents per share: $cents spread over $quantity shares, rounded to whole cents.
     */
    public static function perShare(int $cents, string $quantity): int
    {
        if (self::isZero($quantity)) {
            throw new InvalidArgumentException('A per-share value needs a non-zero quantity.');
        }

        return self::toCents(bcdiv((string) $cents, $quantity, self::WORKING_SCALE));
    }

    /**
     * A quantity for display or a name: no trailing zeros.
     */
    public static function trim(string $value): string
    {
        $text = self::of($value);

        return mb_rtrim(mb_rtrim($text, '0'), '.');
    }
}
