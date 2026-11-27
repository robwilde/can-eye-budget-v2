<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

use App\DTOs\CsvFormat;
use App\Enums\BankImportStatus;
use App\Enums\CsvSignConvention;
use App\Enums\ImportPreset;
use App\Exceptions\CsvPreparationException;
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
 * is read up front, and the import is queued.
 *
 * Strict (the REST API): the import is queued only when the whole file can be read, so a file with an
 * invalid row or no rows leaves nothing behind. Not strict: rows that cannot be read are left out, as the
 * import job skips them, and the account's saved mapping is not updated from a file with such rows.
 * A file with no usable columns, or one that cannot be read at all, is refused either way.
 */
final readonly class CsvImporter
{
    public const string DISK = 'local';

    public const string DIRECTORY = 'bank-imports';

    public function __construct(
        private CsvImportPreparer $preparer,
        private CsvParserService $parser,
        private BankImportRowBuilder $rows,
    ) {}

    /**
     * @throws ValidationException when the account does not take CSV imports or the file cannot be read
     */
    public function import(User $user, Account $account, UploadedFile $file, ImportPreset $preset, bool $strict = true): BankImport
    {
        if (! $this->preparer->accepts($user, $account)) {
            throw ValidationException::withMessages(['account_id' => __('You cannot import a CSV into an account connected via your bank.')]);
        }

        $storedPath = $file->storeAs(self::DIRECTORY.'/'.$user->id, Str::uuid()->toString().'.csv', self::DISK);

        if ($storedPath === false) {
            throw new RuntimeException('The uploaded CSV could not be stored.');
        }

        try {
            $bankImport = DB::transaction(fn (): BankImport => $this->create($user, $account, $file, $preset, $storedPath, $strict));
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($storedPath);

            throw $exception;
        }

        ImportCsvTransactionsJob::dispatch($bankImport);

        return $bankImport;
    }

    private function create(User $user, Account $account, UploadedFile $file, ImportPreset $preset, string $storedPath, bool $strict): BankImport
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

        if ($strict) {
            $this->assertEveryRowReadable($bankImport);
        }

        if ($preset->usesBankMapping() && ($strict || ! $bankImport->rows()->invalid()->exists())) {
            Account::query()->where('user_id', $user->id)->whereKey($account->id)->update(['column_mapping' => $mapping]);
        }

        return $bankImport;
    }

    /**
     * @throws ValidationException
     */
    private function assertEveryRowReadable(BankImport $bankImport): void
    {
        if ($bankImport->rows()->count() === 0) {
            throw $this->unreadable(__('That file has no transactions to import.'));
        }

        $invalid = $bankImport->rows()->invalid()->count();

        if ($invalid > 0) {
            throw $this->unreadable(trans_choice(':count row could not be read with the detected format settings.|:count rows could not be read with the detected format settings.', $invalid));
        }
    }

    /**
     * The column mapping and format the import screen would suggest for this file, checked so that a date and an
     * amount can be read from it.
     *
     * @return array<string, string|null>
     */
    private function mapping(Account $account, ImportPreset $preset, string $path): array
    {
        try {
            $mapping = $this->preparer->prepare($account, $preset, $path)->mapping;
        } catch (CsvPreparationException $exception) {
            throw $this->unreadable($exception->holdsSeveralAccounts
                ? __('That file holds more than one account (:names). Export one account at a time.', ['names' => implode(', ', $exception->accounts)])
                : __('That file could not be read as a CSV.'));
        }

        $this->ensureMappable($mapping);
        $this->ensureHasRows($mapping, $path);

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
     * @param  array<string, string|null>  $mapping
     */
    private function ensureHasRows(array $mapping, string $path): void
    {
        try {
            $lines = $this->parser->lineCount($path, CsvFormat::fromMapping($mapping));
        } catch (Throwable) {
            throw $this->unreadable(__('That file could not be read as a CSV.'));
        }

        if ((CsvFormat::fromMapping($mapping)->skipRows ?? 0) >= $lines) {
            throw $this->unreadable(__('That file has no transactions to import.'));
        }
    }

    private function unreadable(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }
}
