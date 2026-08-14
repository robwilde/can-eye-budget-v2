<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Redbark returns a currency either as a plain ISO string or as an object carrying a
 * `code` key, on both /accounts and /balances rows (sure-finance handles both in
 * app/models/redbark_account/data_helpers.rb:60-73). Normalising at the DTO boundary
 * keeps every downstream consumer on a plain string.
 */
final class RedbarkCurrency
{
    public static function normalise(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = mb_trim($value);

            return $trimmed === '' ? null : mb_strtoupper($trimmed);
        }

        if (is_array($value)) {
            return self::normalise($value['code'] ?? null);
        }

        return null;
    }

    /**
     * The three-letter code, or the fallback when the value is blank or malformed.
     */
    public static function normaliseOr(mixed $value, string $fallback): string
    {
        $code = self::normalise($value);

        return $code !== null && mb_strlen($code) === 3 ? $code : $fallback;
    }
}
