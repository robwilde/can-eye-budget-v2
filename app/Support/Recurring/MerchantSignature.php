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
 * bcx:int codes, card masks (#1234) and dates.
 *
 * Rule of thumb: keep a whitespace token only when it reads like a word — no
 * digits, no '#'/':' reference markers, at least two characters after trimming
 * edge punctuation.
 */
final class MerchantSignature
{
    public static function for(string $raw): string
    {
        $upper = mb_strtoupper(mb_trim($raw));
        $tokens = preg_split('/\s+/', $upper) ?: [];

        $kept = [];

        foreach ($tokens as $token) {
            $token = self::trimEdges($token);

            if (mb_strlen($token) < 2) {
                continue;
            }

            // Reference markers (Ref#, NET#, bcx:int, …) and any token carrying a
            // digit (account/policy/transaction codes such as DT.4Y16G4) are the
            // parts that vary between occurrences of the same payee.
            if (str_contains($token, '#') || str_contains($token, ':')) {
                continue;
            }

            if (preg_match('/\d/', $token) === 1) {
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

        return implode(' ', $kept);
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
