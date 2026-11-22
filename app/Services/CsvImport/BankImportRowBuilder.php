<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

use App\DTOs\CsvFormat;
use App\Enums\CsvSignConvention;
use App\Enums\TransactionDirection;
use App\Models\BankImport;
use App\Models\BankImportRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Parses a stored CSV into BankImportRow records, valid or not, so the user can fix invalid
 * cells before importing. Building replaces any rows the import already had.
 */
final readonly class BankImportRowBuilder
{
    private const int CHUNK = 500;

    public function __construct(private CsvParserService $parser, private BankImportValueMapping $values, private CsvNumberParser $numbers) {}

    /**
     * @param  bool  $suggest  also fill in starting choices for the file's category and tag values
     */
    public function build(BankImport $import, bool $suggest = false): void
    {
        $mapping = $import->column_mapping ?? [];
        $path = Storage::disk('local')->path($import->stored_path);

        DB::transaction(function () use ($import, $mapping, $path, $suggest): void {
            $import->rows()->delete();

            $batch = [];

            foreach ($this->parser->rows($path, $mapping) as $number => $result) {
                $batch[] = $this->attributes($import, $mapping, $number, $result);

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
     * @param  array{row: array<string, string>, date: CarbonImmutable|null, amount: int|null, direction: TransactionDirection|null, errors: array<string, string>}  $result
     * @return array<string, mixed>
     */
    private function attributes(BankImport $import, array $mapping, int $number, array $result): array
    {
        $row = $result['row'];
        $cell = static fn (string $field): string => mb_trim($row[$mapping[$field] ?? ''] ?? '');
        $amountCell = $this->signedAmountCell($row, $mapping);
        $category = $cell(CsvColumnMapper::FIELD_CATEGORY);
        $tag = $cell(CsvColumnMapper::FIELD_TAG);
        $now = now();

        return [
            'bank_import_id' => $import->id,
            'row_number' => $number,
            'date' => $result['date']?->toDateString(),
            'raw_date' => mb_substr($cell(CsvColumnMapper::FIELD_DATE), 0, 50) ?: null,
            'amount' => $result['amount'],
            'raw_amount' => mb_substr($amountCell, 0, 50) ?: null,
            'direction' => $result['direction']?->value,
            'description' => mb_substr($cell(CsvColumnMapper::FIELD_DESCRIPTION), 0, 255),
            'category_value' => $category === '' ? null : mb_substr($category, 0, 255),
            'tag_value' => $tag === '' ? null : mb_substr($tag, 0, 255),
            'errors' => $result['errors'] === [] ? null : json_encode($result['errors']),
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
