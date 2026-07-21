<?php

declare(strict_types=1);

namespace App\Support\Email;

/**
 * Extracts the structured payment breakdown from a BNPL receipt email
 * (Afterpay's "Payment confirmation" and PayPal's Pay-in-4 plan-payment
 * layouts). Falls back to null for anything that is not a recognisable
 * receipt, so callers keep showing a plain snippet.
 *
 * The returned shape is JSON-serialisable and rendered directly:
 *
 * @phpstan-type ReceiptLineItem array{merchant: string, reference: string|null, installment: string|null, amount: int}
 * @phpstan-type Receipt array{total: int|null, date: string|null, method: string|null, last4: string|null, items: list<ReceiptLineItem>, type: string|null, seller: string|null, balance: int|null, loanReference: string|null}
 */
final class ReceiptParser
{
    private const string PAYPAL_LABELS = 'Payment amount|Payment type|Payment method|Posted on|Seller|Current balance|Loan reference number';

    /**
     * @return Receipt|null
     */
    public static function parse(?string $textBody, ?string $htmlBody): ?array
    {
        $text = self::flatten($textBody, $htmlBody);

        if ($text === '') {
            return null;
        }

        return self::afterpay($text) ?? self::payPal($text, $textBody.' '.$htmlBody);
    }

    /**
     * Normalises an email's bodies to a single whitespace-collapsed string:
     * prefers the plain-text part, else strips style/script/head blocks and
     * tags from the HTML and decodes entities. Shared with GmailService so the
     * snippet and the parser flatten identically.
     */
    public static function flatten(?string $textBody, ?string $htmlBody): string
    {
        $body = mb_trim((string) $textBody);

        if ($body === '') {
            $html = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', ' ', (string) $htmlBody) ?? '';
            $html = preg_replace('/<[^>]+>/', ' ', $html) ?? '';
            $body = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $body = str_replace("\u{00A0}", ' ', $body);

        return mb_trim(preg_replace('/\s+/', ' ', $body) ?? '');
    }

    /**
     * @return Receipt|null
     */
    private static function afterpay(string $text): ?array
    {
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
            'type' => null,
            'seller' => null,
            'balance' => null,
            'loanReference' => null,
        ];
    }

    /**
     * @return Receipt|null
     */
    private static function payPal(string $text, string $rawBodies): ?array
    {
        $total = self::money($text, '/Payment amount\s+\$([\d,]+\.\d{2})/i');

        $date = preg_match('/Posted on\s+(\d{1,2}\s+[A-Za-z]+\s+\d{4})/i', $text, $m) === 1 ? $m[1] : null;
        $seller = self::payPalField($text, 'Seller');
        $loanReference = self::loanReference($text, $rawBodies);

        if ($total === null || ($date === null && $seller === null && $loanReference === null)) {
            return null;
        }

        [$method, $last4] = self::payPalMethod($text);

        return [
            'total' => $total,
            'date' => $date,
            'method' => $method,
            'last4' => $last4,
            'items' => [],
            'type' => self::payPalField($text, 'Payment type'),
            'seller' => $seller,
            'balance' => self::money($text, '/Current balance\s+\$([\d,]+\.\d{2})/i'),
            'loanReference' => $loanReference,
        ];
    }

    private static function payPalField(string $text, string $label): ?string
    {
        if (preg_match('/'.preg_quote($label, '/').'\s+(.+?)\s*(?='.self::PAYPAL_LABELS.')/iu', $text, $m) !== 1) {
            return null;
        }

        $value = mb_trim($m[1]);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private static function payPalMethod(string $text): array
    {
        $value = self::payPalField($text, 'Payment method');

        if ($value === null) {
            return [null, null];
        }

        if (preg_match('/^(.+?)\s+(?:x-|[•*]{2,}\s*)?(\d{4})$/u', $value, $m) === 1) {
            return [mb_trim($m[1]), $m[2]];
        }

        return [$value, null];
    }

    private static function loanReference(string $text, string $rawBodies): ?string
    {
        if (preg_match('/Loan reference number\s+([A-Za-z0-9][A-Za-z0-9-]{7,})/i', $text, $m) === 1) {
            return $m[1];
        }

        if (preg_match('~paypal\.com/myaccount/ppcredit/plans/([A-Za-z0-9-]{8,})~i', $rawBodies, $m) === 1) {
            return $m[1];
        }

        return null;
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

        $block = preg_replace('/^.*?Payment method\b.*?\d{4}\b\s*/isu', '', $block) ?? '';

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
            $merchant = mb_trim(preg_replace('/\s+/', ' ', $match[1]));
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
