<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

use App\DTOs\CsvFormat;
use App\Enums\TransactionDirection;
use App\Exceptions\CsvRowInvalid;
use App\Models\BankImportRow;
use InvalidArgumentException;

/**
 * Applies one cell edit to a stored import row and checks the row again against the import's
 * format. The amount is the signed amount to record: negative is money out.
 */
final readonly class BankImportRowEditor
{
    public const array FIELDS = ['date', 'amount', 'description', 'category_value', 'tag_value'];

    public function __construct(private CsvParserService $parser) {}

    public function update(BankImportRow $row, string $field, string $value): BankImportRow
    {
        $value = mb_trim($value);
        $format = CsvFormat::fromMapping($row->bankImport->column_mapping ?? []) ?? new CsvFormat;
        $errors = $row->errors ?? [];

        unset($errors[$field]);

        match ($field) {
            'date' => $this->setDate($row, $value, $format, $errors),
            'amount' => $this->setAmount($row, $value, $format, $errors),
            'description' => $row->description = mb_substr($value, 0, 255),
            'category_value' => $row->category_value = $value === '' ? null : mb_substr($value, 0, 255),
            'tag_value' => $row->tag_value = $value === '' ? null : mb_substr($value, 0, 255),
            default => throw new InvalidArgumentException('Unknown field.'),
        };

        $row->errors = $errors === [] ? null : $errors;
        $row->save();

        return $row;
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function setDate(BankImportRow $row, string $value, CsvFormat $format, array &$errors): void
    {
        $row->raw_date = mb_substr($value, 0, 50);

        try {
            $row->date = $this->parser->cellDate($value, $format);
        } catch (CsvRowInvalid $exception) {
            $row->date = null;
            $errors['date'] = $exception->getMessage();
        }
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function setAmount(BankImportRow $row, string $value, CsvFormat $format, array &$errors): void
    {
        $row->raw_amount = mb_substr($value, 0, 50);
        $cents = app(CsvNumberParser::class)->parse($value, $format->numberFormat);

        if ($cents === null) {
            $row->amount = null;
            $row->direction = null;
            $errors['amount'] = sprintf('Amount "%s" is not a number in the format %s', $value, $format->numberFormat->label());

            return;
        }

        $row->amount = $cents;
        $row->direction = $cents < 0 ? TransactionDirection::Debit : TransactionDirection::Credit;
    }
}
