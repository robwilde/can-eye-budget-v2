<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BankImportStatus;
use App\Enums\CategorySource;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Models\BankImport;
use App\Models\Transaction;
use App\Services\CsvImport\BankImportRowBuilder;
use App\Services\CsvImport\BankImportValueMapping;
use App\Services\CsvImport\CsvParserService;
use App\Services\CsvImport\ParsedTransactionDto;
use App\Services\FeedCategoryMatcher;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\TransactionIngestor;
use App\Support\Audit\AttributeJobToUser;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
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
            new AttributeJobToUser($this->bankImport->user_id),
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
        $categoryMatcher = app(FeedCategoryMatcher::class);
        $valueMapping = app(BankImportValueMapping::class);
        $explicitValues = $bankImport->value_mapping !== null;
        $unresolved = [];
        $path = Storage::disk('local')->path($bankImport->stored_path);
        $earliest = null;
        $touch = static function (mixed $date) use (&$earliest): void {
            $day = CarbonImmutable::parse($date)->startOfDay();

            if ($earliest === null || $day->lessThan($earliest)) {
                $earliest = $day;
            }
        };
        $rebuild = static function () use (&$earliest, $bankImport): void {
            if ($earliest !== null && $bankImport->account !== null) {
                app(AccountBalanceHistoryBuilder::class)->rebuild($bankImport->account, $earliest);
            }
        };

        try {
            if (! $bankImport->rows()->exists()) {
                app(BankImportRowBuilder::class)->build($bankImport);
            }

            $tagChoices = $valueMapping->tagChoices($bankImport);
            $occurrences = [];

            foreach ($bankImport->rows()->orderBy('row_number')->cursor() as $importRow) {
                if (! $importRow->isValid() || $importRow->date === null || $importRow->amount === null || $importRow->direction === null) {
                    continue;
                }

                $rowCount++;
                $key = CsvParserService::hashFor($importRow->date, $importRow->amount, (string) $importRow->description);
                $occurrence = $occurrences[$key] = ($occurrences[$key] ?? -1) + 1;
                $row = new ParsedTransactionDto(
                    postDate: CarbonImmutable::parse($importRow->date),
                    amount: $importRow->amount,
                    direction: $importRow->direction,
                    description: (string) $importRow->description,
                    balance: null,
                    rawRow: [],
                    csvHash: CsvParserService::hashFor($importRow->date, $importRow->amount, (string) $importRow->description, $occurrence),
                    category: $importRow->category_value,
                    tag: $importRow->tag_value,
                );

                try {
                    $categoryId = null;

                    if ($explicitValues) {
                        $categoryId = $valueMapping->categoryFor($bankImport, $row->category);
                    } elseif ($row->category !== null) {
                        $categoryId = $categoryMatcher->match($bankImport->user_id, $row->category);

                        if ($categoryId === null) {
                            $unresolved[$row->category] = ($unresolved[$row->category] ?? 0) + 1;
                        }
                    }

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
                        $touch($row->postDate);
                        DB::transaction(function () use ($ingestor, $valueMapping, $bankImport, $row, $values, $categoryId, $tagChoices): void {
                            $createdRow = $ingestor->ingest(new Transaction([
                                'account_id' => $bankImport->account_id,
                                'csv_hash' => $row->csvHash,
                                'bank_import_id' => $bankImport->id,
                                ...$values,
                                ...($categoryId === null ? [] : ['category_id' => $categoryId, 'category_source' => CategorySource::Feed]),
                            ]), rebuildHistory: false);
                            $valueMapping->attachTags($bankImport, $createdRow, $row->tag, $tagChoices);
                        });
                        $imported++;

                        continue;
                    }

                    $previousDate = $existing->post_date;

                    if ($existing->trashed()) {
                        if ($existing->folded_into_transaction_id !== null) {
                            continue; // folded fee: stays hidden; merged parent already carries its amount
                        }

                        $existing->fill($values);
                        $existing->restored_by_bank_import_id = $bankImport->id;
                        self::fillEmptyCategory($existing, $categoryId);
                        DB::transaction(function () use ($existing, $valueMapping, $bankImport, $row, $tagChoices): void {
                            $existing->restore();
                            $valueMapping->attachTags($bankImport, $existing, $row->tag, $tagChoices);
                        });
                        $touch($previousDate);
                        $touch($row->postDate);
                        $restored++;

                        continue;
                    }

                    $existing->fill($values);
                    self::fillEmptyCategory($existing, $categoryId);

                    if ($existing->bank_import_id === $bankImport->id) {
                        $existing->restored_by_bank_import_id = null;
                    }

                    $existing->save();

                    if ($existing->wasChanged()) {
                        $touch($previousDate);
                        $touch($row->postDate);
                    }

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

            $rebuild();
        } catch (Throwable $e) {
            try {
                $rebuild();
            } catch (Throwable $rebuildException) {
                Log::error('CSV import history rebuild failed', [
                    'bankImportId' => $bankImport->id,
                    'exception' => $rebuildException,
                ]);
            }

            $bankImport->update([
                'status' => BankImportStatus::Failed,
                'error_summary' => $e->getMessage(),
                'row_count' => $rowCount,
                'imported_count' => $imported,
                'skipped_count' => $skipped,
                'restored_count' => $restored,
                'row_errors' => $rowErrors !== [] ? $rowErrors : null,
                'unresolved_categories' => self::unresolvedReport($unresolved),
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
            'unresolved_categories' => self::unresolvedReport($unresolved),
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
        EnrichMerchantBrandsJob::dispatch($bankImport->user);
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

    private static function fillEmptyCategory(Transaction $transaction, ?int $categoryId): void
    {
        if ($categoryId !== null && $transaction->category_id === null) {
            $transaction->fillUnlocked(['category_id' => $categoryId, 'category_source' => CategorySource::Feed]);
        }
    }

    /**
     * @param  array<string, int>  $unresolved
     * @return list<array{value: string, rows: int}>|null
     */
    private static function unresolvedReport(array $unresolved): ?array
    {
        if ($unresolved === []) {
            return null;
        }

        $report = [];

        foreach ($unresolved as $value => $rows) {
            $report[] = ['value' => (string) $value, 'rows' => $rows];
        }

        return $report;
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
