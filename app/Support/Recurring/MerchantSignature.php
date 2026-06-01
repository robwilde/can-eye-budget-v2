<?php

declare(strict_types=1);

namespace App\Support\Recurring;

/**
 * Derives a stable "payee signature" from a bank transaction description.
 *
 * Recurring detection groups transactions by this signature, so it must keep
 * the meaningful payee words and discard the per-transaction noise that
 * otherwise splits one payee into many singletons: reference numbers, account
 * numbers, direct-debit codes (DT.xxxx), Ref#/NET#/MOBILE#/BPAY# markers,
 * bcx:int codes, card masks (#1234), dates, and random alphanumeric references.
 *
 * A token is kept when it reads like a payee word. It is dropped as a code when
 * it carries a '#'/':' marker, or contains a digit without being a plain
 * "number-word" — a single digit run at one boundary adjacent to a letter body,
 * e.g. "7-ELEVEN" or "4WD". This preserves merchant names that contain digits
 * while still discarding interspersed/multi-run codes (DT.4Y16G4, E5MRRQ7T) and
 * pure numbers (86400, 64699390).
 *
 * If every token is filtered out, the raw normalized string is returned rather
 * than an empty signature, so unrelated all-code descriptions are not merged.
 */
final class MerchantSignature
{
    public static function for(string $raw): string
    {
        $normalized = self::normalize($raw);
        $tokens = preg_split('/\s+/', $normalized) ?: [];

        $kept = [];

        foreach ($tokens as $token) {
            $token = self::trimEdges($token);

            if (mb_strlen($token) < 2) {
                continue;
            }

            if (self::isCodeToken($token)) {
                continue;
            }

            // Collapse an adjacent duplicate word (e.g. "MCF MCF", "NETFLIX.COM
            // NETFLIX.COM") which some statement formats emit; non-adjacent
            // repeats (e.g. "TO ... TO") are preserved so distinct payees stay apart.
            if ($kept !== [] && end($kept) === $token) {
                continue;
            }

            $kept[] = $token;
        }

        return $kept === [] ? $normalized : implode(' ', $kept);
    }

    private static function normalize(string $raw): string
    {
        return mb_strtoupper(mb_trim((string) preg_replace('/\s+/', ' ', $raw)));
    }

    /**
     * A token is a reference/account code (drop it) when it carries a '#'/':'
     * marker, or it contains a digit and is not a plain number-word.
     */
    private static function isCodeToken(string $token): bool
    {
        if (str_contains($token, '#') || str_contains($token, ':')) {
            return true;
        }

        if (preg_match('/\d/', $token) !== 1) {
            return false;
        }

        // Number-word: a single digit run at one boundary next to a letter body,
        // e.g. "7-ELEVEN", "4WD", "LEVEL5". Anything else with a digit (pure
        // numbers, DT.4Y16G4, E5MRRQ7T) is a code.
        $leadingDigits = preg_match('/^\d+-?\p{L}[\p{L}.&\'\/-]*$/u', $token) === 1;
        $trailingDigits = preg_match('/^\p{L}[\p{L}.&\'\/-]*-?\d+$/u', $token) === 1;

        return ! ($leadingDigits || $trailingDigits);
    }

    /**
     * Strip leading/trailing non-alphanumerics while preserving internal
     * punctuation, so "-NETFLIX.COM" -> "NETFLIX.COM" and "TMR-PRODUCT" is kept
     * intact, while a lone "-" collapses to an empty (dropped) token.
     */
    private static function trimEdges(string $token): string
    {
        return preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $token) ?? $token;
    }
}
