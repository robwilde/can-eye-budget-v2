<?php

declare(strict_types=1);

namespace App\Services\Statement;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads a date printed on a statement line. A date that does not fit the format, or that is
 * not a real calendar date, is unreadable: it is reported, not guessed.
 */
final class StatementLineDateParser
{
    private const int EARLIEST_YEAR = 1990;

    public function parse(string $raw, ?string $format): ?CarbonImmutable
    {
        $raw = mb_trim($raw);

        if ($raw === '' || $format === null) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!'.$format, $raw);
        } catch (Throwable) {
            return null;
        }

        if ($date === null) {
            return null;
        }

        $problems = CarbonImmutable::getLastErrors();

        if ($problems !== false && ($problems['warning_count'] > 0 || $problems['error_count'] > 0)) {
            return null;
        }

        if ($date->year < self::EARLIEST_YEAR || $date->year > CarbonImmutable::now()->year + 1) {
            return null;
        }

        return $date;
    }
}
