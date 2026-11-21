<?php

declare(strict_types=1);

namespace App\Services\Statement;

use App\DTOs\StatementUploadResult;
use App\Enums\StatementFileType;
use App\Exceptions\Statement\StatementFileRejected;
use App\Models\Account;
use App\Models\Statement;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

/**
 * Files uploaded statements in the user's vault. A file already stored (same content) is
 * skipped and counted; a rejected file is reported and the rest of the batch still succeeds.
 * Uploading never creates imports or transactions.
 */
final class StatementUploadService
{
    public const int MAX_BYTES = 25 * 1024 * 1024;

    private const int CHUNK = 1024 * 1024;

    private const int SNIFF_BYTES = 8192;

    private const int PDF_HEADER_WINDOW = 1024;

    private const array MIME_TYPES = [
        'pdf' => 'application/pdf',
        'csv' => 'text/csv',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(
        private readonly StatementMetadataReader $reader,
        private readonly StatementAccountMatcher $matcher,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws ValidationException when the target account belongs to another user
     */
    public function upload(User $user, array $files, ?Account $account = null): StatementUploadResult
    {
        if ($account !== null && $account->user_id !== $user->id) {
            throw ValidationException::withMessages(['account' => __('That account does not belong to you.')]);
        }

        $created = [];
        $duplicates = 0;
        $errors = [];

        foreach ($files as $file) {
            $name = $file->getClientOriginalName();

            try {
                $statement = $this->file($user, $file, $account);
            } catch (StatementFileRejected $exception) {
                $errors[] = $name.': '.$exception->getMessage();

                continue;
            } catch (Throwable $exception) {
                report($exception);

                $errors[] = $name.': '.__('The file could not be stored.');

                continue;
            }

            if ($statement === null) {
                $duplicates++;

                continue;
            }

            $created[] = $statement;
        }

        return new StatementUploadResult($created, $duplicates, $errors);
    }

    /**
     * @throws StatementFileRejected with a message safe to show when the file is rejected
     */
    private function file(User $user, UploadedFile $file, ?Account $account): ?Statement
    {
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $declared = StatementFileType::tryFrom($extension);

        if ($declared === null) {
            throw new StatementFileRejected(__('Only PDF, CSV and XLSX statements are accepted.'));
        }

        $path = $file->getRealPath();

        if ($path === false) {
            throw new StatementFileRejected(__('The file could not be read.'));
        }

        [$hash, $bytes, $head] = $this->measure($path);

        if ($bytes === 0) {
            throw new StatementFileRejected(__('The file is empty.'));
        }

        if ($this->sniff($head, $path) !== $declared) {
            throw new StatementFileRejected(__('The file contents do not match a :type file.', ['type' => mb_strtoupper($extension)]));
        }

        if (Statement::query()->where('user_id', $user->id)->where('content_hash', $hash)->exists()) {
            return null;
        }

        $storedPath = Storage::disk(Statement::DISK)->putFileAs('statements', $file, Str::uuid()->toString().'.'.$extension);

        if ($storedPath === false) {
            throw new StatementFileRejected(__('The file could not be stored.'));
        }

        try {
            return $this->create($user, $file, $account, $declared, $hash, $bytes, $storedPath);
        } catch (UniqueConstraintViolationException) {
            Storage::disk(Statement::DISK)->delete($storedPath);

            return null;
        } catch (Throwable $exception) {
            Storage::disk(Statement::DISK)->delete($storedPath);

            throw $exception;
        }
    }

    private function create(User $user, UploadedFile $file, ?Account $account, StatementFileType $type, string $hash, int $bytes, string $storedPath): Statement
    {
        $metadata = $this->reader->read(
            Storage::disk(Statement::DISK)->path($storedPath),
            $type,
            $file->getClientOriginalName(),
        );

        return DB::transaction(function () use ($user, $file, $account, $type, $hash, $bytes, $storedPath, $metadata): Statement {
            $statement = new Statement([
                'user_id' => $user->id,
                'account_id' => $account?->id,
                'content_hash' => $hash,
                'original_filename' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'mime_type' => self::MIME_TYPES[$type->value],
                'byte_size' => $bytes,
                'file_type' => $type,
                'institution_hint' => $metadata->institution,
                'account_name_hint' => $metadata->accountName,
                'last_four_hint' => $metadata->lastFour,
                'period_start' => $metadata->periodStart,
                'period_end' => $metadata->periodEnd,
                'opening_balance' => $metadata->openingBalance,
                'closing_balance' => $metadata->closingBalance,
                'currency' => $metadata->currency,
            ]);

            if ($account === null) {
                $this->matcher->suggest($statement);
            }

            $statement->save();

            return $statement;
        });
    }

    /**
     * Streams the file once, stopping as soon as it passes the size limit so a stream of
     * unknown size is never read in full.
     *
     * @return array{0: string, 1: int, 2: string}
     *
     * @throws StatementFileRejected
     */
    private function measure(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new StatementFileRejected(__('The file could not be read.'));
        }

        $context = hash_init('sha256');
        $bytes = 0;
        $head = '';

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, self::CHUNK);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $bytes += mb_strlen($chunk, '8bit');

                if ($bytes > self::MAX_BYTES) {
                    throw new StatementFileRejected(__('The file is larger than 25 MB.'));
                }

                if (mb_strlen($head, '8bit') < self::SNIFF_BYTES) {
                    $head .= mb_substr($chunk, 0, self::SNIFF_BYTES - mb_strlen($head, '8bit'), '8bit');
                }

                hash_update($context, $chunk);
            }
        } finally {
            fclose($handle);
        }

        return [hash_final($context), $bytes, $head];
    }

    private function sniff(string $head, string $path): ?StatementFileType
    {
        if (str_contains(mb_substr($head, 0, self::PDF_HEADER_WINDOW, '8bit'), '%PDF-')) {
            return StatementFileType::Pdf;
        }

        if (str_starts_with($head, "PK\x03\x04")) {
            return $this->isWorkbook($path) ? StatementFileType::Xlsx : null;
        }

        if (str_contains($head, "\0") || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $head) === 1) {
            return null;
        }

        return StatementFileType::Csv;
    }

    private function isWorkbook(string $path): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return false;
        }

        $isWorkbook = $zip->locateName('xl/workbook.xml') !== false;
        $zip->close();

        return $isWorkbook;
    }
}
