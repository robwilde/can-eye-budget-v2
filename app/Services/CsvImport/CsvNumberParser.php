<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

use App\Enums\CsvNumberFormat;

/**
 * Reads an amount cell in the number format the user chose. A cell that is not a number in
 * that format is null, never a guess, so the row fails validation.
 */
final class CsvNumberParser
{
    private const int MAX_DIGITS = 12;

    private const string SPACES = '\s\x{00A0}\x{202F}';

    /**
     * @return int|null signed cents
     */
    public function parse(string $raw, CsvNumberFormat $format): ?int
    {
        $raw = mb_trim($raw);

        if ($raw === '') {
            return null;
        }

        $body = mb_trim(preg_replace('/[$€£¥]|^(?:AUD|USD|EUR|GBP|NZD)\s*/iu', '', $raw) ?? '');
        $negative = str_starts_with($body, '-') || str_ends_with($body, '-') || (str_starts_with($body, '(') && str_ends_with($body, ')'));
        $body = mb_ltrim(mb_trim($body, "()- \t"), '+');

        $normalised = $this->normalise($body, $format);

        if ($normalised === null) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $normalised, 2), 2, '');

        if (mb_strlen($whole) > self::MAX_DIGITS) {
            return null;
        }

        $cents = ((int) $whole) * 100 + (int) mb_substr(mb_str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }

    /**
     * The amount written the way the file writes numbers, so what the user sees can be typed back.
     */
    public function format(int $cents, CsvNumberFormat $format): string
    {
        $sign = $cents < 0 ? '-' : '';
        $whole = intdiv(abs($cents), 100);
        $fraction = mb_str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);

        return match ($format) {
            CsvNumberFormat::CommaThousandsDotDecimal => $sign.$whole.'.'.$fraction,
            CsvNumberFormat::DotThousandsCommaDecimal, CsvNumberFormat::SpaceThousandsCommaDecimal => $sign.$whole.','.$fraction,
            CsvNumberFormat::CommaThousandsOnly => $sign.$whole,
        };
    }

    private function normalise(string $body, CsvNumberFormat $format): ?string
    {
        $spaces = self::SPACES;

        return match ($format) {
            CsvNumberFormat::CommaThousandsDotDecimal => preg_match('/^(\d{1,3}(,\d{3})+|\d+)(\.\d{1,2})?$/', $body) === 1 ? str_replace(',', '', $body) : null,
            CsvNumberFormat::DotThousandsCommaDecimal => preg_match('/^(\d{1,3}(\.\d{3})+|\d+)(,\d{1,2})?$/', $body) === 1 ? str_replace(',', '.', str_replace('.', '', $body)) : null,
            CsvNumberFormat::SpaceThousandsCommaDecimal => preg_match("/^(\\d{1,3}([{$spaces}]\\d{3})+|\\d+)(,\\d+)?$/u", $body) === 1
                ? str_replace(',', '.', (string) preg_replace("/[{$spaces}]/u", '', $body))
                : null,
            CsvNumberFormat::CommaThousandsOnly => preg_match('/^(\d{1,3}(,\d{3})+|\d+)$/', $body) === 1 ? str_replace(',', '', $body) : null,
        };
    }
}
