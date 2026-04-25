<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BankImportStatus;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\BankImport;
use App\Models\Transaction;
use App\Services\CsvImport\CsvParserService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
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
    public function handle(CsvParserService $parser): void
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
        $skipped = 0;
        $rowCount = 0;
        $mapping = $bankImport->column_mapping ?? [];
        $path = Storage::disk('local')->path($bankImport->stored_path);

        try {
            foreach ($parser->eachRow($path, $mapping) as $row) {
                $rowCount++;

                $result = Transaction::query()->updateOrCreate(
                    [
                        'account_id' => $bankImport->account_id,
                        'csv_hash' => $row->csvHash,
                    ],
                    [
                        'user_id' => $bankImport->user_id,
                        'amount' => $row->amount,
                        'direction' => $row->direction,
                        'description' => $row->description,
                        'post_date' => $row->postDate,
                        'transaction_date' => $row->postDate,
                        'status' => TransactionStatus::Posted,
                        'source' => TransactionSource::Csv,
                    ],
                );

                if ($result->wasRecentlyCreated) {
                    $imported++;
                } else {
                    $skipped++;
                }
            }
        } catch (Throwable $e) {
            $bankImport->update([
                'status' => BankImportStatus::Failed,
                'error_summary' => $e->getMessage(),
                'row_count' => $rowCount,
                'imported_count' => $imported,
                'skipped_count' => $skipped,
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
            'completed_at' => now(),
        ]);

        Log::info('CSV import complete', [
            'bankImportId' => $bankImport->id,
            'userId' => $bankImport->user_id,
            'rowCount' => $rowCount,
            'imported' => $imported,
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
}
