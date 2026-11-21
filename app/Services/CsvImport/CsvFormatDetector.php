<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

use App\DTOs\CsvFormat;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Works out which date formats and delimiter a file can plausibly use, so the wizard offers
 * only choices that fit what is in the file.
 */
final class CsvFormatDetector
{
    /** PHP's Y accepts one to four digits, so 15/03/24 would read as the year 24. */
    private const int MIN_FOUR_DIGIT_YEAR = 1000;

    /**
     * The date formats every sample parses in.
     *
     * @param  list<string>  $samples
     * @return list<string>
     */
    public function dateFormats(array $samples): array
    {
        $samples = array_values(array_filter(array_map(mb_trim(...), $samples), static fn (string $sample): bool => $sample !== ''));

        if ($samples === []) {
            return [];
        }

        return array_values(array_filter(
            array_keys(CsvFormat::DATE_FORMATS),
            fn (string $format): bool => array_reduce($samples, fn (bool $all, string $sample): bool => $all && $this->parses($sample, $format), true),
        ));
    }

    public function delimiter(string $firstLine): string
    {
        return mb_substr_count($firstLine, ';') > mb_substr_count($firstLine, ',') ? ';' : ',';
    }

    public function parses(string $raw, string $format): bool
    {
        try {
            $date = CarbonImmutable::createFromFormat('!'.$format, $raw);
        } catch (Throwable) {
            return false;
        }

        $problems = CarbonImmutable::getLastErrors();

        if ($date === null || ($problems !== false && ($problems['warning_count'] > 0 || $problems['error_count'] > 0))) {
            return false;
        }

        return ! str_contains($format, 'Y') || $date->year >= self::MIN_FOUR_DIGIT_YEAR;
    }
}
