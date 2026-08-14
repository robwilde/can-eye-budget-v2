<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads what a Redbark row is actually describing.
 *
 * Uncleared card authorisations arrive with a useless `description` — the literal string
 * "AUTHORISATION" — and the payee only in `merchantName`:
 *
 *     description  "AUTHORISATION"
 *     merchantName "HARRIS FARM MARKETS PTY LWEST END     AU"
 *
 * which is the same row online banking lists under *Uncleared Transactions* as
 * "Hold HARRIS FARM MARKETS PTY L Auth 123023 VCC 023835".
 *
 * Two consequences, both handled here rather than guessed at each call site:
 *
 * 1. The narration must fall back to `merchantName`, otherwise every hold reads
 *    "AUTHORISATION" and they are indistinguishable to the user.
 * 2. **The placeholder is the only signal that a row is a hold.** Redbark reports these with
 *    `status: "posted"`, and `includePending=true` returns a byte-identical response, so the
 *    documented `"pending"` literal never appears for this institution. Measured across 886
 *    captured rows, "AUTHORISATION" was the sole description shared by more than two distinct
 *    merchants (17 rows, 10 merchants), and its amounts matched the bank's uncleared list
 *    exactly — so it is a reliable marker rather than a coincidence of narration.
 */
final class RedbarkNarration
{
    /** Lower-cased descriptions that carry no information about the transaction. */
    private const array PLACEHOLDERS = ['authorisation', 'authorization'];

    public static function isPlaceholder(?string $description): bool
    {
        return in_array(mb_strtolower(mb_trim((string) $description)), self::PLACEHOLDERS, true);
    }

    /**
     * A row is an uncleared authorisation when the bank sent a placeholder narration, or when
     * Redbark did label it pending.
     */
    public static function isHold(?string $description, ?string $status): bool
    {
        return self::isPlaceholder($description) || mb_strtolower(mb_trim((string) $status)) === 'pending';
    }

    /**
     * The best available narration: the bank's own, unless it is a placeholder, in which case
     * the merchant. Never blank.
     */
    public static function describe(?string $description, ?string $merchantName): string
    {
        $description = mb_trim((string) $description);
        $merchantName = mb_trim((string) $merchantName);

        if ($description !== '' && ! self::isPlaceholder($description)) {
            return $description;
        }

        if ($merchantName !== '') {
            return $merchantName;
        }

        return $description !== '' ? $description : 'Transaction';
    }
}
