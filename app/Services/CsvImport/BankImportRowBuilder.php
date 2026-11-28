<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

use App\DTOs\CsvFormat;
use App\Enums\CsvSignConvention;
use App\Enums\ImportPreset;
use App\Enums\TransactionDirection;
use App\Models\BankImport;
use App\Models\BankImportRow;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Parses a stored CSV or QIF file into BankImportRow records, valid or not, so the user can fix invalid
 * cells before importing. Building replaces any rows the import already had.
 */
final readonly class BankImportRowBuilder
{
    public const string QIF_DATE_ORDER = 'qif_date_order';

    private const int CHUNK = 500;

    public function __construct(private CsvParserService $parser, private BankImportValueMapping $values, private CsvNumberParser $numbers, private QifParserService $qif) {}

    /**
     * @param  bool  $suggest  also fill in starting choices for the file's category and tag values
     */
    public function build(BankImport $import, bool $suggest = false): void
    {
        $path = Storage::disk('local')->path($import->stored_path);

        if ($import->preset === ImportPreset::Qif) {
            $order = $this->qif->orderFor($path, (string) ($import->column_mapping[self::QIF_DATE_ORDER] ?? QifParserService::ORDER_AUTO));
            $import->column_mapping = [
                self::QIF_DATE_ORDER => (string) ($import->column_mapping[self::QIF_DATE_ORDER] ?? QifParserService::ORDER_AUTO),
                CsvFormat::KEY_DATE => $order === QifParserService::ORDER_MONTH_FIRST ? 'm/d/Y' : 'd/m/Y',
                CsvFormat::KEY_NUMBER => $this->qif->numberFormatFor($path)->value,
            ];
            $import->save();
        }

        $mapping = $import->column_mapping ?? [];

        DB::transaction(function () use ($import, $mapping, $path, $suggest): void {
            $import->rows()->delete();

            $batch = [];

            foreach ($this->cells($import, $mapping, $path) as $number => $cells) {
                $batch[] = $this->attributes($import, $number, $cells);

                if (count($batch) >= self::CHUNK) {
                    BankImportRow::query()->insert($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                BankImportRow::query()->insert($batch);
            }

            if ($suggest) {
                $import->update(['value_mapping' => $this->values->suggest($import)]);
            }
        });
    }

    /**
     * @param  array<string, string|null>  $mapping
     * @return Generator<int, array{date: CarbonImmutable|null, amount: int|null, direction: TransactionDirection|null, errors: array<string, string>, rawDate: string, rawAmount: string, description: string, category: string, tag: string}>
     */
    private function cells(BankImport $import, array $mapping, string $path): Generator
    {
        if ($import->preset === ImportPreset::Qif) {
            foreach ($this->qif->rows($path, ($mapping[CsvFormat::KEY_DATE] ?? null) === 'm/d/Y' ? QifParserService::ORDER_MONTH_FIRST : QifParserService::ORDER_DAY_FIRST, CsvFormat::fromMapping($mapping)?->numberFormat) as $number => $result) {
                yield $number => [
                    'date' => $result['date'],
                    'amount' => $result['amount'],
                    'direction' => $result['direction'],
                    'errors' => $result['errors'],
                    'rawDate' => $result['raw']['date'],
                    'rawAmount' => $result['raw']['amount'],
                    'description' => $result['description'],
                    'category' => (string) $result['category'],
                    'tag' => (string) $result['tag'],
                ];
            }

            return;
        }

        foreach ($this->parser->rows($path, $mapping) as $number => $result) {
            $row = $result['row'];
            $cell = static fn (string $field): string => mb_trim($row[$mapping[$field] ?? ''] ?? '');

            yield $number => [
                'date' => $result['date'],
                'amount' => $result['amount'],
                'direction' => $result['direction'],
                'errors' => $result['errors'],
                'rawDate' => $cell(CsvColumnMapper::FIELD_DATE),
                'rawAmount' => $this->signedAmountCell($row, $mapping),
                'description' => $cell(CsvColumnMapper::FIELD_DESCRIPTION),
                'category' => $cell(CsvColumnMapper::FIELD_CATEGORY),
                'tag' => $cell(CsvColumnMapper::FIELD_TAG),
            ];
        }
    }

    /**
     * @param  array{date: CarbonImmutable|null, amount: int|null, direction: TransactionDirection|null, errors: array<string, string>, rawDate: string, rawAmount: string, description: string, category: string, tag: string}  $cells
     * @return array<string, mixed>
     */
    private function attributes(BankImport $import, int $number, array $cells): array
    {
        $now = now();

        return [
            'bank_import_id' => $import->id,
            'row_number' => $number,
            'date' => $cells['date']?->toDateString(),
            'raw_date' => mb_substr($cells['rawDate'], 0, 50) ?: null,
            'amount' => $cells['amount'],
            'raw_amount' => mb_substr($cells['rawAmount'], 0, 50) ?: null,
            'direction' => $cells['direction']?->value,
            'description' => mb_substr($cells['description'], 0, 255),
            'category_value' => $cells['category'] === '' ? null : mb_substr($cells['category'], 0, 255),
            'tag_value' => $cells['tag'] === '' ? null : mb_substr($cells['tag'], 0, 255),
            'errors' => $cells['errors'] === [] ? null : json_encode($cells['errors']),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * The amount cell as the amount to record: a debit cell, a cell in a negative-is-credit file or
     * a cell whose type column marks money out is shown negative, so the user can retype it as is.
     * When a debit or credit cell is unreadable, that one is shown.
     *
     * @param  array<string, string>  $row
     * @param  array<string, string|null>  $mapping
     */
    private function signedAmountCell(array $row, array $mapping): string
    {
        $format = CsvFormat::fromMapping($mapping);
        $cell = static fn (string $field): string => mb_trim($row[$mapping[$field] ?? ''] ?? '');
        $outflow = static fn (string $text): string => '-'.mb_ltrim($text, '-+');
        $unreadable = fn (string $text): bool => $text !== '' && $format !== null && $this->numbers->parse($text, $format->numberFormat) === null;

        if (($mapping[CsvColumnMapper::FIELD_DEBIT] ?? null) !== null || ($mapping[CsvColumnMapper::FIELD_CREDIT] ?? null) !== null) {
            $credit = $cell(CsvColumnMapper::FIELD_CREDIT);
            $debit = $cell(CsvColumnMapper::FIELD_DEBIT);

            return match (true) {
                $unreadable($credit) => $credit,
                $unreadable($debit) => $outflow($debit),
                $credit !== '' => $credit,
                $debit !== '' => $outflow($debit),
                default => '',
            };
        }

        $text = $cell(CsvColumnMapper::FIELD_AMOUNT);

        if ($text === '' || $format === null) {
            return $text;
        }

        return match ($format->signConvention) {
            CsvSignConvention::NegativeIsDebit => $text,
            CsvSignConvention::NegativeIsCredit => str_starts_with($text, '-') ? mb_substr($text, 1) : '-'.$text,
            CsvSignConvention::TypeColumn => mb_strtolower($cell(CsvFormat::TYPE_COLUMN)) === mb_strtolower($format->typeInflowValue) ? mb_ltrim($text, '-+') : $outflow($text),
        };
    }
}
