<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

use App\DTOs\CsvFormat;
use App\Enums\CsvNumberFormat;
use App\Enums\CsvSignConvention;
use App\Enums\ImportPreset;

/**
 * The column mapping and file format a Mint, YNAB or Actual Budget export is written in, matched
 * to the headers of the file at hand. A column the file does not have is left out, so the user
 * can map it by hand.
 */
final class ImportPresetMapper
{
    private const array COLUMNS = [
        'mint' => [
            CsvColumnMapper::FIELD_DATE => ['Date'],
            CsvColumnMapper::FIELD_DESCRIPTION => ['Description', 'Original Description'],
            CsvColumnMapper::FIELD_AMOUNT => ['Amount'],
            CsvColumnMapper::FIELD_CATEGORY => ['Category'],
            CsvColumnMapper::FIELD_TAG => ['Labels'],
            CsvFormat::TYPE_COLUMN => ['Transaction Type'],
        ],
        'ynab' => [
            CsvColumnMapper::FIELD_DATE => ['Date'],
            CsvColumnMapper::FIELD_DESCRIPTION => ['Payee', 'Memo'],
            CsvColumnMapper::FIELD_DEBIT => ['Outflow'],
            CsvColumnMapper::FIELD_CREDIT => ['Inflow'],
            CsvColumnMapper::FIELD_CATEGORY => ['Category', 'Category Group/Category'],
        ],
        'actual_budget' => [
            CsvColumnMapper::FIELD_DATE => ['Date'],
            CsvColumnMapper::FIELD_DESCRIPTION => ['Payee', 'Notes'],
            CsvColumnMapper::FIELD_AMOUNT => ['Amount'],
            CsvColumnMapper::FIELD_CATEGORY => ['Category'],
            CsvColumnMapper::FIELD_SPLIT_AMOUNT => ['Split_Amount'],
        ],
    ];

    private const array ACCOUNT_COLUMNS = [
        'mint' => 'Account Name',
        'ynab' => 'Account',
        'actual_budget' => 'Account',
    ];

    /**
     * The header of the column that names each row's account, when the file has one.
     *
     * @param  list<string>  $headers
     */
    public function accountColumn(ImportPreset $preset, array $headers): ?string
    {
        $wanted = self::ACCOUNT_COLUMNS[$preset->value] ?? null;

        foreach ($headers as $header) {
            if ($wanted !== null && mb_strtolower(mb_trim($header)) === mb_strtolower($wanted)) {
                return $header;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string|null> the columns found plus the preset's format, as stored in a column mapping
     */
    public function mapping(ImportPreset $preset, array $headers): array
    {
        $byName = [];

        foreach ($headers as $header) {
            $byName[mb_strtolower(mb_trim($header))] ??= $header;
        }

        $mapping = [];

        foreach (self::COLUMNS[$preset->value] ?? [] as $field => $candidates) {
            foreach ($candidates as $candidate) {
                if (isset($byName[mb_strtolower($candidate)])) {
                    $mapping[$field] = $byName[mb_strtolower($candidate)];

                    break;
                }
            }
        }

        return [...$mapping, ...$this->format($preset)->toMapping()];
    }

    public function format(ImportPreset $preset): CsvFormat
    {
        return match ($preset) {
            ImportPreset::Mint => new CsvFormat(dateFormat: 'm/d/Y', numberFormat: CsvNumberFormat::CommaThousandsDotDecimal, signConvention: CsvSignConvention::TypeColumn, typeInflowValue: 'credit'),
            ImportPreset::Ynab => new CsvFormat(dateFormat: 'm/d/Y', numberFormat: CsvNumberFormat::CommaThousandsDotDecimal),
            ImportPreset::ActualBudget => new CsvFormat(dateFormat: 'Y-m-d', numberFormat: CsvNumberFormat::CommaThousandsDotDecimal),
            ImportPreset::BankCsv, ImportPreset::Qif => new CsvFormat,
        };
    }
}
