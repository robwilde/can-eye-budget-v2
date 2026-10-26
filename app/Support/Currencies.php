<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The currencies a user can pick for a base currency, an account or a transaction. Codes are
 * three-letter ISO 4217; the list is the pool the F44 allowed-currencies preference narrows.
 */
final class Currencies
{
    public const array NAMES = [
        'AED' => 'UAE dirham',
        'AUD' => 'Australian dollar',
        'BRL' => 'Brazilian real',
        'CAD' => 'Canadian dollar',
        'CHF' => 'Swiss franc',
        'CNY' => 'Chinese yuan',
        'CZK' => 'Czech koruna',
        'DKK' => 'Danish krone',
        'EUR' => 'Euro',
        'FJD' => 'Fijian dollar',
        'GBP' => 'British pound',
        'HKD' => 'Hong Kong dollar',
        'HUF' => 'Hungarian forint',
        'IDR' => 'Indonesian rupiah',
        'ILS' => 'Israeli shekel',
        'INR' => 'Indian rupee',
        'JPY' => 'Japanese yen',
        'KRW' => 'South Korean won',
        'MXN' => 'Mexican peso',
        'MYR' => 'Malaysian ringgit',
        'NOK' => 'Norwegian krone',
        'NZD' => 'New Zealand dollar',
        'PHP' => 'Philippine peso',
        'PLN' => 'Polish zloty',
        'SAR' => 'Saudi riyal',
        'SEK' => 'Swedish krona',
        'SGD' => 'Singapore dollar',
        'THB' => 'Thai baht',
        'TRY' => 'Turkish lira',
        'TWD' => 'New Taiwan dollar',
        'USD' => 'US dollar',
        'VND' => 'Vietnamese dong',
        'WST' => 'Samoan tala',
        'ZAR' => 'South African rand',
    ];

    public static function isKnown(string $code): bool
    {
        return isset(self::NAMES[$code]);
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::NAMES);
    }

    /**
     * Upper-cases and filters to known codes, keeping the first occurrence of each.
     *
     * @param  array<int|string, mixed>  $codes
     * @return list<string>
     */
    public static function normaliseList(array $codes): array
    {
        $normalised = [];

        foreach ($codes as $code) {
            if (is_string($code) && self::isKnown($upper = mb_strtoupper(mb_trim($code)))) {
                $normalised[$upper] = $upper;
            }
        }

        return array_values($normalised);
    }
}
