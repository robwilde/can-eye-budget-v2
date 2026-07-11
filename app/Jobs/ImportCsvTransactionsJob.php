<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BankImportStatus;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\BankImport;
use App\Models\Transaction;
use App\Services\CsvImport\CsvParserService;
use App\Services\TransactionIngestor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ImportCsvTransactionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 1800;

    public function __construct(
        public readonly BankImport $bankImport,
    ) {}

    public function uniqueId(): int
    {
        return $this->bankImport->id;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping("import-csv-{$this->bankImport->id}"),
        ];
    }

    /**
     * @throws Throwable
     */
    public function handle(CsvParserService $parser, TransactionIngestor $ingestor): void
    {
        $bankImport = $this->bankImport->fresh();

        if ($bankImport === null) {
            return;
        }

        $bankImport->update([
            'status' => BankImportStatus::Importing,
            'started_at' => now(),
        ]);

        $imported = 0;
        $updated = 0;
        $restored = 0;
        $skipped = 0;
        $rowCount = 0;
        $rowErrors = [];
        $mapping = $bankImport->column_mapping ?? [];
        $path = Storage::disk('local')->path($bankImport->stored_path);

        try {
            foreach ($parser->eachRow($path, $mapping) as $row) {
                $rowCount++;

                try {
                    $existing = Transaction::withTrashed()
                        ->where('account_id', $bankImport->account_id)
                        ->where('csv_hash', $row->csvHash)
                        ->first();

                    $values = [
                        'user_id' => $bankImport->user_id,
                        'amount' => $row->amount,
                        'direction' => $row->direction,
                        'description' => $row->description,
                        'post_date' => $row->postDate,
                        'transaction_date' => $row->postDate,
                        'status' => TransactionStatus::Posted,
                        'source' => TransactionSource::Csv,
                    ];

                    if ($existing === null) {
                        $ingestor->ingest(new Transaction([
                            'account_id' => $bankImport->account_id,
                            'csv_hash' => $row->csvHash,
                            ...$values,
                        ]));
                        $imported++;

                        continue;
                    }

                    if ($existing->trashed()) {
                        if ($existing->folded_into_transaction_id !== null) {
                            continue; // folded fee: stays hidden; merged parent already carries its amount
                        }

                        $existing->fill($values);
                        $existing->restore();
                        $restored++;

                        continue;
                    }

                    $existing->fill($values)->save();
                    $updated++;
                } catch (Throwable $rowException) {
                    $skipped++;
                    $rowErrors[] = [
                        'row' => $rowCount,
                        'description' => $row->description ?? null,
                        'message' => self::userSafeRowError($rowException),
                    ];

                    Log::warning('CSV import row failed', [
                        'bankImportId' => $bankImport->id,
                        'row' => $rowCount,
                        'exception' => $rowException,
                    ]);
                }
            }
        } catch (Throwable $e) {
            $bankImport->update([
                'status' => BankImportStatus::Failed,
                'error_summary' => $e->getMessage(),
                'row_count' => $rowCount,
                'imported_count' => $imported,
                'skipped_count' => $skipped,
                'restored_count' => $restored,
                'row_errors' => $rowErrors !== [] ? $rowErrors : null,
                'completed_at' => now(),
            ]);

            Log::error('ImportCsvTransactionsJob failed', [
                'bankImportId' => $bankImport->id,
                'userId' => $bankImport->user_id,
                'exception' => $e,
            ]);

            throw $e;
        }

        $bankImport->update([
            'status' => BankImportStatus::Completed,
            'row_count' => $rowCount,
            'imported_count' => $imported,
            'skipped_count' => $skipped,
            'restored_count' => $restored,
            'row_errors' => $rowErrors !== [] ? $rowErrors : null,
            'error_summary' => $rowErrors === []
                ? null
                : sprintf('%d row(s) failed; see details.', count($rowErrors)),
            'completed_at' => now(),
        ]);

        Log::info('CSV import complete', [
            'bankImportId' => $bankImport->id,
            'userId' => $bankImport->user_id,
            'rowCount' => $rowCount,
            'imported' => $imported,
            'updated' => $updated,
            'restored' => $restored,
            'skipped' => $skipped,
        ]);

        RunTransactionAnalysisJob::dispatch($bankImport->user);
    }

    public function failed(Throwable $exception): void
    {
        $this->bankImport->fresh()?->update([
            'status' => BankImportStatus::Failed,
            'error_summary' => $exception->getMessage(),
            'completed_at' => now(),
        ]);

        Log::error('ImportCsvTransactionsJob failed (lifecycle)', [
            'bankImportId' => $this->bankImport->id,
            'exception' => $exception,
        ]);
    }

    private static function userSafeRowError(Throwable $e): string
    {
        return match (true) {
            $e instanceof UniqueConstraintViolationException => 'Duplicate row detected for this account.',
            $e instanceof QueryException => 'Database rejected this row.',
            default => 'Could not import this row.',
        };
    }
}
