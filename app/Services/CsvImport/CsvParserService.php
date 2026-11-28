<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

use App\DTOs\CsvFormat;
use App\Enums\CsvSignConvention;
use App\Enums\TransactionDirection;
use App\Exceptions\CsvRowInvalid;
use Carbon\CarbonImmutable;
use Generator;
use InvalidArgumentException;
use League\Csv\Exception;
use League\Csv\Reader;
use League\Csv\SyntaxError;
use Throwable;

final class CsvParserService
{
    /**
     * The hash a row is stored under: its date, amount and description, plus how many identical
     * rows came before it in the file.
     */
    public static function hashFor(CarbonImmutable $date, int $amountCents, string $description, int $occurrence = 0): string
    {
        $base = hash('sha256', implode('|', [$date->format('Y-m-d'), (string) $amountCents, mb_strtolower(mb_trim($description))]));

        return $occurrence === 0 ? $base : hash('sha256', $base.'|'.$occurrence);
    }

    /**
     * @return list<string>
     *
     * @throws SyntaxError|Exception
     */
    public function headers(string $path, ?CsvFormat $format = null): array
    {
        $reader = $this->reader($path, $format);

        return array_values(array_map(
            static fn (string $h): string => mb_trim($h),
            $reader->getHeader(),
        ));
    }

    /**
     * @param  array<string, string|null>  $mapping
     * @return list<ParsedTransactionDto>
     *
     * @throws Exception
     */
    public function preview(string $path, array $mapping, int $limit = 20): array
    {
        $rows = [];
        $count = 0;

        foreach ($this->eachRow($path, $mapping) as $row) {
            $rows[] = $row;
            $count++;

            if ($count >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, string|null>  $mapping
     * @return Generator<int, ParsedTransactionDto>
     *
     * @throws Exception
     */
    public function eachRow(string $path, array $mapping): Generator
    {
        $format = CsvFormat::fromMapping($mapping);
        $occurrences = [];

        foreach ($this->records($path, $format) as $offset => $record) {
            $row = $this->normalizeRow($record);

            if ($this->isSplitParent($row, $mapping)) {
                continue;
            }

            try {
                $parsed = $this->parseRow($row, $mapping, $format, $occurrences);
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($parsed === null) {
                continue;
            }

            yield $offset => $parsed;
        }
    }

    /**
     * Every data row, valid or not. The date and the amount are read separately, so a row with
     * one bad cell keeps the other as parsed, and each failed cell is named with its reason.
     * This is what the clean step stores so the user can fix invalid cells before importing.
     *
     * @param  array<string, string|null>  $mapping
     * @return Generator<int, array{row: array<string, string>, date: CarbonImmutable|null, amount: int|null, direction: TransactionDirection|null, errors: array<string, string>}>
     *
     * @throws Exception
     */
    public function rows(string $path, array $mapping): Generator
    {
        $format = CsvFormat::fromMapping($mapping);
        $number = 0;

        foreach ($this->records($path, $format) as $record) {
            $number++;
            $row = $this->normalizeRow($record);

            if ($this->isSplitParent($row, $mapping)) {
                continue;
            }

            $errors = [];
            $date = null;
            $amount = null;
            $direction = null;
            $rawDate = $row[$mapping[CsvColumnMapper::FIELD_DATE] ?? ''] ?? '';

            if ($rawDate === '') {
                $errors['date'] = 'Missing date';
            } else {
                try {
                    $date = $format === null ? $this->parseDate($rawDate) : $this->parseDateIn($rawDate, $format);
                } catch (Throwable $exception) {
                    $errors['date'] = $exception->getMessage();
                }
            }

            try {
                [$amount, $direction] = $this->parseAmount($row, $mapping, $format);

                if ($amount === null) {
                    $errors['amount'] = 'Missing amount';
                    $direction = null;
                }
            } catch (CsvRowInvalid $exception) {
                $errors['amount'] = $exception->getMessage();
            }

            yield $number => ['row' => $row, 'date' => $date, 'amount' => $amount, 'direction' => $direction, 'errors' => $errors];
        }
    }

    /**
     * @param  array<string, string|null>  $mapping
     *
     * @throws Exception
     */
    public function summarize(string $path, array $mapping): PreviewSummary
    {
        $rowCount = 0;
        $earliest = null;
        $latest = null;
        $debitTotal = 0;
        $creditTotal = 0;
        $errors = [];
        $duplicateCount = 0;
        $closingBalance = null;
        $closingDate = null;

        $format = CsvFormat::fromMapping($mapping);
        $occurrences = [];

        foreach ($this->records($path, $format) as $offset => $record) {
            $row = $this->normalizeRow($record);

            if ($this->isSplitParent($row, $mapping)) {
                continue;
            }

            try {
                $parsed = $this->parseRow($row, $mapping, $format, $occurrences);
            } catch (Throwable $e) {
                $errors[] = ['row' => (int) $offset, 'error' => $e->getMessage()];

                continue;
            }

            if ($parsed === null) {
                $errors[] = ['row' => (int) $offset, 'error' => 'Missing date or amount'];

                continue;
            }

            $rowCount++;

            if ($earliest === null || $parsed->postDate->lessThan($earliest)) {
                $earliest = $parsed->postDate;
            }

            if ($latest === null || $parsed->postDate->greaterThan($latest)) {
                $latest = $parsed->postDate;
            }

            if ($parsed->balance !== null && ($closingDate === null || $parsed->postDate->greaterThanOrEqualTo($closingDate))) {
                $closingDate = $parsed->postDate;
                $closingBalance = $parsed->balance;
            }

            if ($parsed->direction === TransactionDirection::Debit) {
                $debitTotal += abs($parsed->amount);
            } else {
                $creditTotal += $parsed->amount;
            }

        }

        return new PreviewSummary(
            rowCount: $rowCount,
            earliestDate: $earliest,
            latestDate: $latest,
            totalDebits: $debitTotal,
            totalCredits: $creditTotal,
            duplicateCount: $duplicateCount,
            errorRows: $errors,
            closingBalance: $closingBalance,
        );
    }

    /**
     * How many lines the file has, headers and skipped rows included.
     *
     * @throws Exception
     */
    public function lineCount(string $path, ?CsvFormat $format = null): int
    {
        $reader = Reader::from($path);
        $reader->setDelimiter($format->delimiter ?? ',');
        $reader->skipInputBOM();

        return iterator_count($reader->getRecords());
    }

    /**
     * Up to $limit non-empty values of one column, for detecting formats.
     *
     * @return list<string>
     *
     * @throws Exception
     */
    public function sampleValues(string $path, string $column, ?CsvFormat $format = null, int $limit = 50): array
    {
        $values = [];

        foreach ($this->records($path, $format) as $record) {
            $value = mb_trim((string) ($this->normalizeRow($record)[$column] ?? ''));

            if ($value !== '') {
                $values[] = $value;
            }

            if (count($values) >= $limit) {
                break;
            }
        }

        return $values;
    }

    /**
     * The first few different values of a column, so a file that mixes accounts can be spotted.
     *
     * @return list<string>
     *
     * @throws Exception
     */
    public function distinctValues(string $path, string $column, ?CsvFormat $format = null, int $limit = 2): array
    {
        $values = [];

        foreach ($this->records($path, $format) as $record) {
            $value = mb_trim((string) ($this->normalizeRow($record)[$column] ?? ''));

            if ($value !== '') {
                $values[mb_strtolower($value)] ??= $value;
            }

            if (count($values) >= $limit) {
                break;
            }
        }

        return array_values($values);
    }

    /**
     * @throws CsvRowInvalid
     */
    public function cellDate(string $raw, CsvFormat $format): CarbonImmutable
    {
        return $this->parseDateIn($raw, $format);
    }

    /**
     * The data rows. With a header offset League CSV still returns the lines above the header,
     * matched against the headers, so they are dropped here.
     *
     * @return Generator<int, array<string, string|null>>
     */
    private function records(string $path, ?CsvFormat $format): Generator
    {
        $skip = $format->skipRows ?? 0;

        foreach ($this->reader($path, $format)->getRecords() as $offset => $record) {
            if ($offset > $skip || $skip === 0) {
                yield $offset => $record;
            }
        }
    }

    /**
     * @return Reader<array<string, string|null>>
     *
     * @throws Exception
     */
    private function reader(string $path, ?CsvFormat $format = null): Reader
    {
        $reader = Reader::from($path);
        $reader->setDelimiter($format->delimiter ?? ',');
        $reader->setHeaderOffset($format->skipRows ?? 0);
        $reader->skipInputBOM();

        return $reader;
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, string>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalized[mb_trim($key)] = mb_trim($value ?? '');
        }

        return $normalized;
    }

    /**
     * A split parent of an Actual Budget export: it writes the total in the Split_Amount column and 0 in
     * Amount, and its children carry the amounts. A row with no Split_Amount, or a zero one, is never a parent.
     *
     * @param  array<string, string>  $row
     * @param  array<string, string|null>  $mapping
     */
    private function isSplitParent(array $row, array $mapping): bool
    {
        $splitColumn = $mapping[CsvColumnMapper::FIELD_SPLIT_AMOUNT] ?? null;

        if ($splitColumn === null || $splitColumn === '') {
            return false;
        }

        $isZero = static fn (string $cell): bool => preg_match('/[1-9]/', $cell) !== 1;

        return ! $isZero($row[$splitColumn] ?? '') && $isZero($row[$mapping[CsvColumnMapper::FIELD_AMOUNT] ?? ''] ?? '');
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string|null>  $mapping
     * @param  array<string, int>  $occurrences  how many rows with the same date, amount and description came before
     *
     * @throws InvalidArgumentException when a format was chosen and the row does not fit it
     */
    private function parseRow(array $row, array $mapping, ?CsvFormat $format, array &$occurrences): ?ParsedTransactionDto
    {
        $dateColumn = $mapping[CsvColumnMapper::FIELD_DATE] ?? null;
        $rawDate = $dateColumn !== null ? ($row[$dateColumn] ?? '') : '';

        if ($rawDate === '') {
            return null;
        }

        $postDate = $format === null ? $this->parseDate($rawDate) : $this->parseDateIn($rawDate, $format);

        [$amountCents, $direction] = $this->parseAmount($row, $mapping, $format);

        if ($amountCents === null) {
            return null;
        }

        $description = $this->parseDescription($row, $mapping);
        $balance = $this->parseBalance($row, $mapping, $format);

        $base = $this->hashRow($postDate, $amountCents, $description);
        $index = $occurrences[$base] ?? 0;
        $occurrences[$base] = $index + 1;

        return new ParsedTransactionDto(
            postDate: $postDate,
            amount: $amountCents,
            direction: $direction,
            description: $description,
            balance: $balance,
            rawRow: $row,
            csvHash: $index === 0 ? $base : hash('sha256', $base.'|'.$index),
            category: $this->parseCategory($row, $mapping),
            tag: $this->parseTag($row, $mapping),
        );
    }

    private function parseDateIn(string $raw, CsvFormat $format): CarbonImmutable
    {
        $raw = mb_trim($raw);

        if (! app(CsvFormatDetector::class)->parses($raw, $format->dateFormat)) {
            throw new CsvRowInvalid(sprintf('Date "%s" does not match the format %s', $raw, CsvFormat::DATE_FORMATS[$format->dateFormat] ?? $format->dateFormat));
        }

        $date = CarbonImmutable::createFromFormat('!'.$format->dateFormat, $raw);

        if ($date->greaterThan(CarbonImmutable::today())) {
            throw new CsvRowInvalid(sprintf('Date "%s" is in the future', $raw));
        }

        return $date->startOfDay();
    }

    private function parseDate(string $raw): CarbonImmutable
    {
        $raw = mb_trim($raw);

        $formats = [
            'd/m/Y',
            'd-m-Y',
            'Y-m-d',
            'd/m/y',
            'm/d/Y',
            'd M Y',
            'd-M-Y',
        ];

        foreach ($formats as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $raw);
            } catch (Throwable) {
                continue;
            }

            if ($parsed instanceof CarbonImmutable && $parsed->format($format) === $raw) {
                return $parsed->startOfDay();
            }
        }

        return CarbonImmutable::parse($raw)->startOfDay();
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string|null>  $mapping
     * @return array{0: int|null, 1: TransactionDirection}
     */
    private function parseAmount(array $row, array $mapping, ?CsvFormat $format = null): array
    {
        $amountCol = $mapping[CsvColumnMapper::FIELD_AMOUNT] ?? null;
        $debitCol = $mapping[CsvColumnMapper::FIELD_DEBIT] ?? null;
        $creditCol = $mapping[CsvColumnMapper::FIELD_CREDIT] ?? null;

        if ($debitCol !== null || $creditCol !== null) {
            $debit = $this->amount($row[$debitCol] ?? '', $format);
            $credit = $this->amount($row[$creditCol] ?? '', $format);

            if ($format !== null) {
                foreach ([[$debitCol, $debit], [$creditCol, $credit]] as [$column, $value]) {
                    $cell = $column === null ? '' : mb_trim($row[$column] ?? '');

                    if ($cell !== '' && $value === null) {
                        throw new CsvRowInvalid(sprintf('Amount "%s" is not a number in the format %s', $cell, $format->numberFormat->label()));
                    }
                }
            }

            if ($credit !== null && $credit !== 0) {
                return [$credit, TransactionDirection::Credit];
            }

            if ($debit !== null && $debit !== 0) {
                return [-abs($debit), TransactionDirection::Debit];
            }

            if ($credit !== null || $debit !== null) {
                return [0, TransactionDirection::Credit];
            }

            return [null, TransactionDirection::Debit];
        }

        if ($amountCol === null) {
            return [null, TransactionDirection::Debit];
        }

        $raw = $row[$amountCol] ?? '';
        $signed = $this->amount($raw, $format);

        if ($signed === null && $format !== null && mb_trim($raw) !== '') {
            throw new CsvRowInvalid(sprintf('Amount "%s" is not a number in the format %s', $raw, $format->numberFormat->label()));
        }

        if ($signed === null) {
            return [null, TransactionDirection::Debit];
        }

        if ($format !== null) {
            $signed = $this->applySign($signed, $row, $mapping, $format);
        }

        return [
            $signed,
            $signed < 0 ? TransactionDirection::Debit : TransactionDirection::Credit,
        ];
    }

    private function amount(string $raw, ?CsvFormat $format): ?int
    {
        return $format === null ? $this->normalizeAmount($raw) : app(CsvNumberParser::class)->parse($raw, $format->numberFormat);
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string|null>  $mapping
     */
    private function applySign(int $signed, array $row, array $mapping, CsvFormat $format): int
    {
        return match ($format->signConvention) {
            CsvSignConvention::NegativeIsDebit => $signed,
            CsvSignConvention::NegativeIsCredit => -$signed,
            CsvSignConvention::TypeColumn => mb_strtolower(mb_trim($row[$mapping[CsvFormat::TYPE_COLUMN] ?? ''] ?? '')) === mb_strtolower($format->typeInflowValue)
                ? abs($signed)
                : -abs($signed),
        };
    }

    private function normalizeAmount(string $raw): ?int
    {
        $raw = mb_trim($raw);

        if ($raw === '') {
            return null;
        }

        $isNegative = false;

        if (str_starts_with($raw, '-') || (str_starts_with($raw, '(') && str_ends_with($raw, ')'))) {
            $isNegative = true;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', $raw) ?? '';
        $clean = mb_ltrim($clean, '-');

        if ($clean === '') {
            return null;
        }

        if (str_contains($clean, '.')) {
            [$dollars, $cents] = explode('.', $clean, 2);
            $cents = mb_substr($cents.'00', 0, 2);
        } else {
            $dollars = $clean;
            $cents = '00';
        }

        $value = (int) $dollars * 100 + (int) $cents;

        return $isNegative ? -$value : $value;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string|null>  $mapping
     */
    private function parseDescription(array $row, array $mapping): string
    {
        $col = $mapping[CsvColumnMapper::FIELD_DESCRIPTION] ?? null;

        if ($col === null) {
            return '';
        }

        return $row[$col] ?? '';
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string|null>  $mapping
     */
    private function parseBalance(array $row, array $mapping, ?CsvFormat $format = null): ?int
    {
        $col = $mapping[CsvColumnMapper::FIELD_BALANCE] ?? null;

        if ($col === null) {
            return null;
        }

        return $this->amount($row[$col] ?? '', $format);
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string|null>  $mapping
     */
    private function parseTag(array $row, array $mapping): ?string
    {
        $col = $mapping[CsvColumnMapper::FIELD_TAG] ?? null;
        $value = $col === null ? '' : mb_trim($row[$col] ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string|null>  $mapping
     */
    private function parseCategory(array $row, array $mapping): ?string
    {
        $col = $mapping[CsvColumnMapper::FIELD_CATEGORY] ?? null;

        if ($col === null) {
            return null;
        }

        $value = $row[$col] ?? '';

        return $value === '' ? null : $value;
    }

    private function hashRow(CarbonImmutable $date, int $amountCents, string $description): string
    {
        return hash('sha256', implode('|', [
            $date->format('Y-m-d'),
            (string) $amountCents,
            mb_strtolower(mb_trim($description)),
        ]));
    }
}
