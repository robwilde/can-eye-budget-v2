<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

use App\Enums\TransactionDirection;
use Carbon\CarbonImmutable;
use Generator;
use League\Csv\Exception;
use League\Csv\Reader;
use League\Csv\SyntaxError;
use Throwable;

final class CsvParserService
{
    /**
     * @return list<string>
     *
     * @throws SyntaxError|Exception
     */
    public function headers(string $path): array
    {
        $reader = $this->reader($path);

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
        $reader = $this->reader($path);
        $records = $reader->getRecords();

        foreach ($records as $offset => $record) {
            $row = $this->normalizeRow($record);
            $parsed = $this->parseRow($row, $mapping);

            if ($parsed === null) {
                continue;
            }

            yield $offset => $parsed;
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
        $seen = [];
        $duplicateCount = 0;

        $reader = $this->reader($path);

        foreach ($reader->getRecords() as $offset => $record) {
            try {
                $row = $this->normalizeRow($record);
                $parsed = $this->parseRow($row, $mapping);
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

            if ($parsed->direction === TransactionDirection::Debit) {
                $debitTotal += abs($parsed->amount);
            } else {
                $creditTotal += $parsed->amount;
            }

            if (isset($seen[$parsed->csvHash])) {
                $duplicateCount++;
            } else {
                $seen[$parsed->csvHash] = true;
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
        );
    }

    /**
     * @return Reader<array<string, string|null>>
     *
     * @throws Exception
     */
    private function reader(string $path): Reader
    {
        $reader = Reader::from($path);
        $reader->setHeaderOffset(0);
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
     * @param  array<string, string>  $row
     * @param  array<string, string|null>  $mapping
     */
    private function parseRow(array $row, array $mapping): ?ParsedTransactionDto
    {
        $dateColumn = $mapping[CsvColumnMapper::FIELD_DATE] ?? null;
        $rawDate = $dateColumn !== null ? ($row[$dateColumn] ?? '') : '';

        if ($rawDate === '') {
            return null;
        }

        $postDate = $this->parseDate($rawDate);

        [$amountCents, $direction] = $this->parseAmount($row, $mapping);

        if ($amountCents === null) {
            return null;
        }

        $description = $this->parseDescription($row, $mapping);
        $balance = $this->parseBalance($row, $mapping);

        $hash = $this->hashRow($postDate, $amountCents, $description);

        return new ParsedTransactionDto(
            postDate: $postDate,
            amount: $amountCents,
            direction: $direction,
            description: $description,
            balance: $balance,
            rawRow: $row,
            csvHash: $hash,
        );
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
    private function parseAmount(array $row, array $mapping): array
    {
        $amountCol = $mapping[CsvColumnMapper::FIELD_AMOUNT] ?? null;
        $debitCol = $mapping[CsvColumnMapper::FIELD_DEBIT] ?? null;
        $creditCol = $mapping[CsvColumnMapper::FIELD_CREDIT] ?? null;

        if ($debitCol !== null || $creditCol !== null) {
            $debit = $this->normalizeAmount($row[$debitCol] ?? '');
            $credit = $this->normalizeAmount($row[$creditCol] ?? '');

            if ($credit !== null && $credit !== 0) {
                return [$credit, TransactionDirection::Credit];
            }

            if ($debit !== null && $debit !== 0) {
                return [-abs($debit), TransactionDirection::Debit];
            }

            return [null, TransactionDirection::Debit];
        }

        if ($amountCol === null) {
            return [null, TransactionDirection::Debit];
        }

        $signed = $this->normalizeAmount($row[$amountCol] ?? '');

        if ($signed === null) {
            return [null, TransactionDirection::Debit];
        }

        return [
            $signed,
            $signed < 0 ? TransactionDirection::Debit : TransactionDirection::Credit,
        ];
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
    private function parseBalance(array $row, array $mapping): ?int
    {
        $col = $mapping[CsvColumnMapper::FIELD_BALANCE] ?? null;

        if ($col === null) {
            return null;
        }

        return $this->normalizeAmount($row[$col] ?? '');
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
