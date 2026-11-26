<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

use App\DTOs\CsvFormat;
use App\Enums\BankImportStatus;
use App\Enums\CsvSignConvention;
use App\Enums\ImportPreset;
use App\Jobs\ImportCsvTransactionsJob;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Imports an uploaded CSV without a review step: the file is stored under the user's own folder with a
 * generated name, its format and columns are detected the way the import screen suggests them, every row
 * is read up front, and the import is queued only when the whole file can be read. A file that cannot be
 * read leaves nothing behind.
 */
final readonly class ApiCsvImporter
{
    public const string DISK = 'local';

    public const string DIRECTORY = 'bank-imports';

    public function __construct(
        private CsvParserService $parser,
        private CsvColumnMapper $mapper,
        private CsvFormatDetector $detector,
        private ImportPresetMapper $presets,
        private BankImportRowBuilder $rows,
    ) {}

    /**
     * @throws ValidationException when the account does not take CSV imports or the file cannot be read
     */
    public function import(User $user, Account $account, UploadedFile $file, ImportPreset $preset): BankImport
    {
        if ($account->user_id !== $user->id || ! $account->is_tracked || ! $account->acceptsCsvImports()) {
            throw ValidationException::withMessages(['account_id' => __('You cannot import a CSV into an account connected via your bank.')]);
        }

        $storedPath = $file->storeAs(self::DIRECTORY.'/'.$user->id, Str::uuid()->toString().'.csv', self::DISK);

        if ($storedPath === false) {
            throw new RuntimeException('The uploaded CSV could not be stored.');
        }

        try {
            $bankImport = DB::transaction(fn (): BankImport => $this->create($user, $account, $file, $preset, $storedPath));
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($storedPath);

            throw $exception;
        }

        ImportCsvTransactionsJob::dispatch($bankImport);

        return $bankImport;
    }

    private function create(User $user, Account $account, UploadedFile $file, ImportPreset $preset, string $storedPath): BankImport
    {
        $path = Storage::disk(self::DISK)->path($storedPath);
        $mapping = $this->mapping($account, $preset, $path);

        $bankImport = BankImport::query()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'stored_path' => $storedPath,
            'status' => BankImportStatus::Pending,
            'preset' => $preset,
            'column_mapping' => $mapping,
        ]);

        try {
            $this->rows->build($bankImport);
        } catch (Throwable) {
            throw $this->unreadable(__('The file could not be read with the detected format settings.'));
        }

        $total = $bankImport->rows()->count();

        if ($total === 0) {
            throw $this->unreadable(__('That file has no transactions to import.'));
        }

        $invalid = $bankImport->rows()->invalid()->count();

        if ($invalid > 0) {
            throw $this->unreadable(trans_choice(':count row could not be read with the detected format settings.|:count rows could not be read with the detected format settings.', $invalid));
        }

        if ($preset->usesBankMapping()) {
            Account::query()->where('user_id', $user->id)->whereKey($account->id)->update(['column_mapping' => $mapping]);
        }

        return $bankImport;
    }

    /**
     * The column mapping and format the import screen would suggest for this file, checked so that
     * every row can be read into a date and an amount.
     *
     * @return array<string, string|null>
     */
    private function mapping(Account $account, ImportPreset $preset, string $path): array
    {
        $existing = $preset->usesBankMapping() ? ($account->column_mapping ?? []) : [];
        $format = CsvFormat::fromMapping($existing) ?? new CsvFormat(delimiter: $this->detector->delimiter($this->firstLine($path)));

        try {
            $headers = $this->parser->headers($path, $format);
        } catch (Throwable) {
            throw $this->unreadable(__('That file could not be read as a CSV.'));
        }

        $mapping = $existing !== [] ? array_merge($this->mapper->suggest($headers), $existing) : $this->mapper->suggest($headers);

        if (! $preset->usesBankMapping()) {
            $this->ensureSingleAccount($preset, $headers, $path, $format);
            $mapping = array_merge($mapping, $this->presetColumns($preset, $headers, $path, $format));
        }

        $mapping = array_merge($this->formatDefaults($mapping, $path, $format), $mapping);

        $this->ensureMappable($mapping);

        try {
            $lines = $this->parser->lineCount($path, CsvFormat::fromMapping($mapping));
        } catch (Throwable) {
            throw $this->unreadable(__('That file could not be read as a CSV.'));
        }

        if ((CsvFormat::fromMapping($mapping)->skipRows ?? 0) >= $lines) {
            throw $this->unreadable(__('That file has no transactions to import.'));
        }

        return $mapping;
    }

    /**
     * @param  array<string, string|null>  $mapping
     */
    private function ensureMappable(array $mapping): void
    {
        $filled = static fn (string $field): bool => ($mapping[$field] ?? '') !== '';

        if (! $filled(CsvColumnMapper::FIELD_DATE)) {
            throw $this->unreadable(__('No date column could be found in that file.'));
        }

        $signed = $filled(CsvColumnMapper::FIELD_AMOUNT);
        $split = $filled(CsvColumnMapper::FIELD_DEBIT) || $filled(CsvColumnMapper::FIELD_CREDIT);

        if ($signed === $split) {
            throw $this->unreadable(__('No single amount column, or debit and credit columns, could be found in that file.'));
        }

        if (($mapping[CsvFormat::KEY_SIGN] ?? null) === CsvSignConvention::TypeColumn->value && ! $filled(CsvFormat::TYPE_COLUMN)) {
            throw $this->unreadable(__('No transaction type column could be found in that file.'));
        }
    }

    /**
     * @param  list<string>  $headers
     */
    private function ensureSingleAccount(ImportPreset $preset, array $headers, string $path, CsvFormat $format): void
    {
        $column = $this->presets->accountColumn($preset, $headers);

        if ($column === null) {
            return;
        }

        try {
            $accounts = $this->parser->distinctValues($path, $column, $format);
        } catch (Throwable) {
            return;
        }

        if (count($accounts) > 1) {
            throw $this->unreadable(__('That file holds more than one account (:names). Export one account at a time.', ['names' => implode(', ', $accounts)]));
        }
    }

    /**
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

    /**
     * @return list<string>
     */
    private function dateFormats(?string $dateColumn, string $path, CsvFormat $format): array
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

    private function unreadable(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }
}
