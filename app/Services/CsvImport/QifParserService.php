<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

use App\Enums\CsvNumberFormat;
use App\Enums\TransactionDirection;
use App\Exceptions\CsvRowInvalid;
use Carbon\CarbonImmutable;
use Generator;
use RuntimeException;

/**
 * Reads the bank, credit-card and cash sections of a Quicken QIF file. Other sections (accounts,
 * categories, investments) are skipped. A record that cannot be read is still returned, with the
 * cell that failed and why, so the clean step can show it for fixing.
 *
 * Quicken writes the date as month/day or day/month depending on the export, so the order is
 * detected from the file when some date settles it; otherwise day first is assumed unless told.
 */
final class QifParserService
{
    public const string ORDER_AUTO = 'auto';

    public const string ORDER_DAY_FIRST = 'dmy';

    public const string ORDER_MONTH_FIRST = 'mdy';

    public const array ORDERS = [self::ORDER_AUTO, self::ORDER_DAY_FIRST, self::ORDER_MONTH_FIRST];

    private const array TRANSACTION_SECTIONS = ['type:bank', 'type:ccard', 'type:cash'];

    private const string DATE_PATTERN = '/^(\d{1,2})[\/.\-](\d{1,2})([\/.\-\'])(\d{1,2}|\d{4})$/';

    public function __construct(private CsvNumberParser $numbers) {}

    /**
     * Every readable transaction of the file, with the hash its rows are deduplicated by.
     *
     * @return list<ParsedTransactionDto>
     *
     * @throws RuntimeException
     */
    public function parse(string $path, string $order = self::ORDER_AUTO): array
    {
        $parsed = [];
        $occurrences = [];

        foreach ($this->rows($path, $order) as $row) {
            if ($row['errors'] !== [] || $row['date'] === null || $row['amount'] === null || $row['direction'] === null) {
                continue;
            }

            $key = CsvParserService::hashFor($row['date'], $row['amount'], $row['description']);
            $occurrence = $occurrences[$key] = ($occurrences[$key] ?? -1) + 1;

            $parsed[] = new ParsedTransactionDto(
                postDate: $row['date'],
                amount: $row['amount'],
                direction: $row['direction'],
                description: $row['description'],
                balance: null,
                rawRow: $row['raw'],
                csvHash: CsvParserService::hashFor($row['date'], $row['amount'], $row['description'], $occurrence),
                category: $row['category'],
                tag: $row['tag'],
            );
        }

        return $parsed;
    }

    /**
     * @return Generator<int, array{raw: array<string, string>, date: CarbonImmutable|null, amount: int|null, direction: TransactionDirection|null, description: string, category: string|null, tag: string|null, errors: array<string, string>}>
     *
     * @throws RuntimeException when the file is not a QIF file or holds no bank or credit-card transactions
     */
    public function rows(string $path, string $order = self::ORDER_AUTO, ?CsvNumberFormat $numberFormat = null): Generator
    {
        $records = $this->records($path);

        if ($records === []) {
            throw new RuntimeException('No bank or credit-card transactions were found in that QIF file.');
        }

        $order = $this->resolveOrder($records, $order);
        $numberFormat ??= $this->resolveNumberFormat($records);
        $number = 0;

        foreach ($records as $record) {
            $number++;
            $errors = [];
            $date = null;
            $amount = null;
            $direction = null;
            $rawDate = $record['D'] ?? '';
            $rawAmount = $record['T'] ?? $record['U'] ?? '';

            if ($rawDate === '') {
                $errors['date'] = 'Missing date';
            } else {
                try {
                    $date = $this->parseDate($rawDate, $order);
                } catch (CsvRowInvalid $exception) {
                    $errors['date'] = $exception->getMessage();
                }
            }

            if ($rawAmount === '') {
                $errors['amount'] = 'Missing amount';
            } else {
                $amount = $this->numbers->parse($rawAmount, $numberFormat);

                if ($amount === null) {
                    $errors['amount'] = sprintf('Amount "%s" is not a number', $rawAmount);
                } else {
                    $direction = $amount < 0 ? TransactionDirection::Debit : TransactionDirection::Credit;
                }
            }

            [$category, $tag] = $this->categoryAndClass($record['L'] ?? '');

            yield $number => [
                'raw' => ['date' => $rawDate, 'amount' => $rawAmount],
                'date' => $date,
                'amount' => $amount,
                'direction' => $direction,
                'description' => mb_substr($record['P'] ?? $record['M'] ?? '', 0, 255),
                'category' => $category,
                'tag' => $tag,
                'errors' => $errors,
            ];
        }
    }

    /**
     * The number format the amounts of a file are read in: the one its amounts settle, or 1,234.56.
     *
     * @throws RuntimeException
     */
    public function numberFormatFor(string $path): CsvNumberFormat
    {
        return $this->resolveNumberFormat($this->records($path));
    }

    /**
     * @param  list<string>  $amounts
     * @return CsvNumberFormat|null 1.234,56 or 1,234.56 when the amounts settle it, otherwise null
     */
    public function detectNumberFormat(array $amounts): ?CsvNumberFormat
    {
        $commaDecimal = false;
        $dotDecimal = false;

        foreach ($amounts as $amount) {
            $digits = (string) preg_replace('/[^\d.,]/u', '', $amount);

            $commaDecimal = $commaDecimal || preg_match('/,\d{1,2}$|\.\d{3}.*,\d+$/', $digits) === 1;
            $dotDecimal = $dotDecimal || preg_match('/\.\d{1,2}$|,\d{3}.*\.\d+$/', $digits) === 1;
        }

        return match (true) {
            $commaDecimal && ! $dotDecimal => CsvNumberFormat::DotThousandsCommaDecimal,
            $dotDecimal && ! $commaDecimal => CsvNumberFormat::CommaThousandsDotDecimal,
            default => null,
        };
    }

    /**
     * The order the dates of a file are read in: the one chosen, or the one the file settles, or day first.
     *
     * @throws RuntimeException
     */
    public function orderFor(string $path, string $order = self::ORDER_AUTO): string
    {
        return $this->resolveOrder($this->records($path), $order);
    }

    /**
     * @param  list<string>  $dates
     * @return string|null day first or month first when a date settles it, otherwise null
     */
    public function detectOrder(array $dates): ?string
    {
        $dayFirst = false;
        $monthFirst = false;

        foreach ($dates as $date) {
            if (preg_match(self::DATE_PATTERN, $this->compact($date), $parts) !== 1) {
                continue;
            }

            $dayFirst = $dayFirst || (int) $parts[1] > 12;
            $monthFirst = $monthFirst || (int) $parts[2] > 12;
        }

        return match (true) {
            $dayFirst && ! $monthFirst => self::ORDER_DAY_FIRST,
            $monthFirst && ! $dayFirst => self::ORDER_MONTH_FIRST,
            default => null,
        };
    }

    /**
     * @param  list<array<string, string>>  $records
     */
    private function resolveOrder(array $records, string $order): string
    {
        if ($order !== self::ORDER_AUTO) {
            return $order;
        }

        return $this->detectOrder(array_map(fn (array $record): string => $record['D'] ?? '', $records)) ?? self::ORDER_DAY_FIRST;
    }

    /**
     * @param  list<array<string, string>>  $records
     */
    private function resolveNumberFormat(array $records): CsvNumberFormat
    {
        return $this->detectNumberFormat(array_map(fn (array $record): string => $record['T'] ?? $record['U'] ?? '', $records)) ?? CsvNumberFormat::CommaThousandsDotDecimal;
    }

    /**
     * @return list<array<string, string>> the first value of each field letter, per transaction
     *
     * @throws RuntimeException
     */
    private function records(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        if (! str_contains(mb_strtolower($content), '!type:') && ! str_contains(mb_strtolower($content), '!account')) {
            throw new RuntimeException('That file is not a QIF file.');
        }

        $records = [];
        $record = [];
        $included = false;
        $account = '';
        $accounts = [];
        $inAccountBlock = false;

        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            $line = mb_rtrim($line);

            if ($line === '') {
                continue;
            }

            $code = $line[0];
            $value = mb_trim(mb_substr($line, 1));

            if ($code === '!') {
                $header = mb_strtolower($value);

                if (str_starts_with($header, 'type:') || str_starts_with($header, 'account')) {
                    $included = in_array($header, self::TRANSACTION_SECTIONS, true);
                    $inAccountBlock = str_starts_with($header, 'account');
                    $record = [];
                }

                continue;
            }

            if ($code === '^') {
                if ($included && $record !== []) {
                    $records[] = $record;
                    $accounts[$account] = true;
                }

                $record = [];

                continue;
            }

            if ($inAccountBlock && $code === 'N') {
                $account = mb_strtolower($value);
                $inAccountBlock = false;

                continue;
            }

            if ($included) {
                $record[$code] ??= $value;
            }
        }

        if ($included && $record !== []) {
            $records[] = $record;
            $accounts[$account] = true;
        }

        if (count($accounts) > 1) {
            throw new RuntimeException('That QIF file holds more than one account. Export one account at a time.');
        }

        return $records;
    }

    /**
     * @throws CsvRowInvalid
     */
    private function parseDate(string $raw, string $order): CarbonImmutable
    {
        $text = $this->compact($raw);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $iso) === 1) {
            [$year, $month, $day] = [(int) $iso[1], (int) $iso[2], (int) $iso[3]];
        } elseif (preg_match(self::DATE_PATTERN, $text, $parts) === 1) {
            [$first, $second] = [(int) $parts[1], (int) $parts[2]];
            [$day, $month] = $order === self::ORDER_MONTH_FIRST ? [$second, $first] : [$first, $second];
            $year = $this->year($parts[4], $parts[3] === "'");
        } else {
            throw new CsvRowInvalid(sprintf('Date "%s" is not a date', $raw));
        }

        if (! checkdate($month, $day, $year)) {
            throw new CsvRowInvalid(sprintf('Date "%s" is not a date in the %s order', $raw, $order === self::ORDER_MONTH_FIRST ? 'month-first' : 'day-first'));
        }

        $date = CarbonImmutable::create($year, $month, $day)->startOfDay();

        if ($date->greaterThan(CarbonImmutable::today())) {
            throw new CsvRowInvalid(sprintf('Date "%s" is in the future', $raw));
        }

        return $date;
    }

    private function year(string $digits, bool $apostrophe): int
    {
        if (mb_strlen($digits) === 4) {
            return (int) $digits;
        }

        $year = (int) $digits;

        return $apostrophe || $year < 70 ? 2000 + $year : 1900 + $year;
    }

    private function compact(string $raw): string
    {
        return (string) preg_replace('/\s+/u', '', $raw);
    }

    /**
     * A QIF category is "Category" or "Category/Class"; a transfer is written "[Account]" and has no category.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function categoryAndClass(string $value): array
    {
        if ($value === '' || str_starts_with($value, '[')) {
            return [null, null];
        }

        [$category, $class] = array_pad(explode('/', $value, 2), 2, '');
        $category = mb_trim($category);
        $class = mb_trim($class);

        return [$category === '' ? null : mb_substr($category, 0, 255), $class === '' ? null : mb_substr($class, 0, 255)];
    }
}
