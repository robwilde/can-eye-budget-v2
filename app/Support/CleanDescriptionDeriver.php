<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Recurring\MerchantSignature;

final class CleanDescriptionDeriver
{
    public const int MAX_LENGTH = 255;

    public static function fromDescription(?string $raw): ?string
    {
        $raw = mb_trim((string) $raw);

        if ($raw === '' || RedbarkNarration::isPlaceholder($raw)) {
            return null;
        }

        $signature = MerchantSignature::payeeOrNull((string) preg_replace('/#\d+-(?=\p{L})/u', ' ', $raw));

        if ($signature === null) {
            return null;
        }

        $all = explode(' ', $signature);
        $words = array_values(array_filter(
            $all,
            static fn (string $word): bool => preg_match('/^X{2,}\d+$/', $word) !== 1,
        ));

        while (preg_match('/^X{2,}\d+$/', (string) end($all)) === 1 && count($words) > 1 && in_array(end($words), ['TO', 'FROM', 'FOR'], true)) {
            array_pop($words);
        }

        if ($words === [] || self::isUsageMarkersOnly($words)) {
            return null;
        }

        return mb_substr(mb_convert_case(mb_strtolower(implode(' ', $words)), MB_CASE_TITLE), 0, self::MAX_LENGTH);
    }

    public static function tidy(?string $name): ?string
    {
        $name = mb_trim((string) preg_replace('/\s+/', ' ', (string) $name));

        return $name === '' ? null : mb_substr($name, 0, self::MAX_LENGTH);
    }

    /**
     * @param  list<string>  $words
     */
    private static function isUsageMarkersOnly(array $words): bool
    {
        $markers = [...MerchantSignature::CARD_NETWORK_TOKENS, MerchantSignature::FOREIGN_MARKER_TOKEN];

        return array_diff($words, $markers) === [];
    }
}
