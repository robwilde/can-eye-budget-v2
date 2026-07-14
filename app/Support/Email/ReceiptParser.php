<?php

declare(strict_types=1);

namespace App\Support\Email;

/**
 * Extracts the structured payment breakdown from a BNPL receipt email
 * (currently Afterpay's "Payment confirmation" layout). Falls back to null
 * for anything that is not a recognisable receipt, so callers keep showing a
 * plain snippet.
 *
 * The returned shape is JSON-serialisable and rendered directly:
 *
 * @phpstan-type ReceiptLineItem array{merchant: string, reference: string|null, installment: string|null, amount: int}
 * @phpstan-type Receipt array{total: int|null, date: string|null, method: string|null, last4: string|null, items: list<ReceiptLineItem>}
 */
final class ReceiptParser
{
    /**
     * @return Receipt|null
     */
    public static function parse(?string $textBody, ?string $htmlBody): ?array
    {
        $text = self::flatten($textBody, $htmlBody);

        if ($text === '') {
            return null;
        }

        $items = self::lineItems($text);
        $total = self::money($text, '/Total amount paid\s+\$([\d,]+\.\d{2})/i');

        if ($items === [] && $total === null) {
            return null;
        }

        [$method, $last4] = self::paymentMethod($text);

        return [
            'total' => $total,
            'date' => self::paymentDate($text),
            'method' => $method,
            'last4' => $last4,
            'items' => $items,
        ];
    }

    private static function flatten(?string $textBody, ?string $htmlBody): string
    {
        $body = mb_trim((string) $textBody);

        if ($body === '') {
            $html = (string) preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', ' ', (string) $htmlBody);
            $html = (string) preg_replace('/<[^>]+>/', ' ', $html);
            $body = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $body = str_replace("\u{00A0}", ' ', $body);

        return mb_trim((string) preg_replace('/\s+/', ' ', $body));
    }

    /**
     * @return list<array{merchant: string, reference: string|null, installment: string|null, amount: int}>
     */
    private static function lineItems(string $text): array
    {
        $block = $text;

        if (preg_match('/Repayments\b(.*)/isu', $text, $bm) === 1) {
            $block = $bm[1];
        }

        $block = (string) preg_replace('/^.*?Payment method\b.*?\d{4}\b\s*/isu', '', $block);

        if (preg_match_all(
            '/([\p{L}\p{N}][\p{L}\p{N} &\'.\-]*?)\s*Order\s*#?(\S+)\s+(\d+)\s+of\s+(\d+)\s+\$([\d,]+\.\d{2})/iu',
            $block,
            $matches,
            PREG_SET_ORDER,
        ) === false || $matches === []) {
            return [];
        }

        $items = [];
        $seen = [];

        foreach ($matches as $match) {
            $merchant = mb_trim((string) preg_replace('/\s+/', ' ', $match[1]));
            $reference = $match[2];
            $amount = self::centsFromString($match[5]);
            $key = $reference.'|'.$match[3].'|'.$amount;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $items[] = [
                'merchant' => $merchant === '' ? 'Order '.$reference : $merchant,
                'reference' => $reference,
                'installment' => $match[3].' of '.$match[4],
                'amount' => $amount,
            ];
        }

        return $items;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private static function paymentMethod(string $text): array
    {
        if (preg_match('/Payment method\s+([A-Za-z][A-Za-z ]{0,20}?)\s*[•*]{2,}\s*(\d{4})\b/iu', $text, $m) === 1) {
            return [mb_trim($m[1]), $m[2]];
        }

        if (preg_match('/Payment method\s+([A-Za-z][A-Za-z ]{0,20}?)\s+(?:ending(?:\s+in)?\s+)?(\d{4})\b/i', $text, $m) === 1) {
            return [mb_trim($m[1]), $m[2]];
        }

        return [null, null];
    }

    private static function paymentDate(string $text): ?string
    {
        if (preg_match('/Payment date\s+((?:[A-Za-z]{3,},?\s+)?\d{1,2}\s+[A-Za-z]+\s+\d{4})/i', $text, $m) === 1) {
            return mb_trim($m[1]);
        }

        return null;
    }

    private static function money(string $text, string $pattern): ?int
    {
        if (preg_match($pattern, $text, $m) === 1) {
            return self::centsFromString($m[1]);
        }

        return null;
    }

    private static function centsFromString(string $value): int
    {
        return (int) round(((float) str_replace(',', '', $value)) * 100);
    }
}
