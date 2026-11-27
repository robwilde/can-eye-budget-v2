<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

use App\DTOs\CsvFormat;
use App\Enums\CsvAccountRefusal;
use App\Enums\ImportPreset;
use App\Exceptions\CsvPreparationException;
use App\Models\Account;
use App\Models\User;
use Throwable;

/**
 * What the import screen and the API share before a CSV is imported: whether the account takes CSV imports,
 * and the format and column mapping detected for the file, the account's saved mapping and the preset.
 * The screen then lets the user adjust the mapping; the API checks it and imports without a review step.
 */
final readonly class CsvImportPreparer
{
    public function __construct(
        private CsvParserService $parser,
        private CsvColumnMapper $mapper,
        private CsvFormatDetector $detector,
        private ImportPresetMapper $presets,
    ) {}

    /**
     * Why the account cannot take a CSV import, or null: it must be the user's own, tracked, and not fed by a
     * bank connection.
     */
    public function refusalFor(User $user, Account $account): ?CsvAccountRefusal
    {
        return match (true) {
            $account->user_id !== $user->id || ! $account->is_tracked => CsvAccountRefusal::NotAvailable,
            ! $account->acceptsCsvImports() => CsvAccountRefusal::BankConnected,
            default => null,
        };
    }

    public function accepts(User $user, Account $account): bool
    {
        return $this->refusalFor($user, $account) === null;
    }

    /**
     * @throws CsvPreparationException when the file cannot be read, or names more than one account
     */
    public function prepare(Account $account, ImportPreset $preset, string $path): CsvPreparation
    {
        $existing = $preset->usesBankMapping() ? ($account->column_mapping ?? []) : [];
        $format = CsvFormat::fromMapping($existing) ?? new CsvFormat(delimiter: $this->detector->delimiter($this->firstLine($path)));

        try {
            $headers = $this->parser->headers($path, $format);
        } catch (Throwable) {
            throw CsvPreparationException::unreadable();
        }

        $mapping = $existing !== [] ? array_merge($this->mapper->suggest($headers), $existing) : $this->mapper->suggest($headers);

        if (! $preset->usesBankMapping()) {
            $accounts = $this->accountsInFile($preset, $headers, $path, $format);

            if (count($accounts) > 1) {
                throw CsvPreparationException::severalAccounts($accounts);
            }

            $mapping = array_merge($mapping, $this->presetColumns($preset, $headers, $path, $format));
        }

        return new CsvPreparation($headers, array_merge($this->formatDefaults($mapping, $path, $format), $mapping));
    }

    /**
     * The date formats that fit every sampled date in the column, best first.
     *
     * @return list<string>
     */
    public function dateFormats(?string $dateColumn, string $path, ?CsvFormat $format): array
    {
        if ($dateColumn === null || $dateColumn === '') {
            return [];
        }

        try {
            return $this->detector->dateFormats($this->parser->sampleValues($path, $dateColumn, $format));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<string>  $headers
     * @return list<string> the first two different accounts the file names
     */
    private function accountsInFile(ImportPreset $preset, array $headers, string $path, CsvFormat $format): array
    {
        $column = $this->presets->accountColumn($preset, $headers);

        if ($column === null) {
            return [];
        }

        try {
            return $this->parser->distinctValues($path, $column, $format);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The columns and format of a Mint, YNAB or Actual Budget export. The preset's date format is kept when
     * every date in the file fits it, otherwise the first one that does is used.
     *
     * @param  list<string>  $headers
     * @return array<string, string|null>
     */
    private function presetColumns(ImportPreset $preset, array $headers, string $path, CsvFormat $format): array
    {
        $columns = $this->presets->mapping($preset, $headers);
        $options = $this->dateFormats($columns[CsvColumnMapper::FIELD_DATE] ?? null, $path, $format);

        if ($options !== [] && ! in_array($columns[CsvFormat::KEY_DATE], $options, true)) {
            $columns[CsvFormat::KEY_DATE] = $options[0];
        }

        $columns[CsvFormat::KEY_DELIMITER] = $format->delimiter;

        return $columns;
    }

    /**
     * @param  array<string, string|null>  $mapping
     * @return array<string, string>
     */
    private function formatDefaults(array $mapping, string $path, CsvFormat $format): array
    {
        $options = $this->dateFormats($mapping[CsvColumnMapper::FIELD_DATE] ?? null, $path, $format);

        return new CsvFormat(
            dateFormat: $options[0] ?? $format->dateFormat,
            numberFormat: $format->numberFormat,
            signConvention: $format->signConvention,
            skipRows: $format->skipRows,
            delimiter: $format->delimiter,
            typeInflowValue: $format->typeInflowValue,
        )->toMapping();
    }

    private function firstLine(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $line = (string) fgets($handle);
        fclose($handle);

        return $line;
    }
}
