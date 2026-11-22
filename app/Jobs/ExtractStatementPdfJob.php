<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\StatementPdfExtractor;
use App\Enums\DocumentStatus;
use App\Enums\PdfImportStatus;
use App\Enums\StatementLineKind;
use App\Exceptions\Statement\StatementExtractionFailed;
use App\Models\Document;
use App\Models\PdfImport;
use App\Models\Statement;
use App\Notifications\PdfImportReadyNotification;
use App\Services\Statement\StatementLineDateParser;
use App\Support\Audit\AttributeJobToUser;
use App\Support\Documents\VectorStoreStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sends a vaulted statement PDF to the AI provider and stores the extracted lines for review.
 * Only bank and credit-card statements produce lines; other documents complete with none.
 * The provider call retries a transient failure once itself, so the job runs once. It uses the
 * long queue connection, whose retry_after is above the job timeout.
 */
final class ExtractStatementPdfJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1000;

    public function __construct(public readonly PdfImport $import)
    {
        $this->onConnection('redis_long');
        $this->onQueue('statements');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new AttributeJobToUser($this->import->user_id),
            new WithoutOverlapping("pdf-import-{$this->import->id}")->expireAfter(1100)->dontRelease(),
        ];
    }

    public function handle(StatementPdfExtractor $extractor, StatementLineDateParser $dates): void
    {
        $import = PdfImport::query()->forUser($this->import->user_id)->find($this->import->id);

        if ($import === null || $import->status !== PdfImportStatus::Pending) {
            return;
        }

        $import->update(['status' => PdfImportStatus::Processing, 'started_at' => now(), 'failure_message' => null]);

        try {
            $bytes = $this->pdfBytes($import);
            $extracted = $extractor->extract($bytes);
        } catch (StatementExtractionFailed $exception) {
            $this->fail($import, $exception->getMessage());

            return;
        }

        $dateFormat = $import->date_format ?? $extracted->dateFormat;

        $stored = DB::transaction(function () use ($import, $extracted, $dates, $dateFormat): bool {
            $current = PdfImport::query()->forUser($import->user_id)->whereKey($import->id)->lockForUpdate()->first();

            if ($current === null || $current->status !== PdfImportStatus::Processing) {
                return false;
            }

            $unreadable = 0;

            foreach ($extracted->lines as $line) {
                $date = $dates->parse($line->rawDate, $dateFormat);
                $unreadable += $date === null ? 1 : 0;

                $import->lines()->create([
                    'line_date' => $date,
                    'raw_date' => $line->rawDate,
                    'date_unreadable' => $date === null,
                    'amount' => $line->amountCents,
                    'direction' => $line->direction,
                    'description' => $line->description,
                    'kind' => StatementLineKind::StatementOnly,
                ]);
            }

            $import->update([
                'status' => PdfImportStatus::Ready,
                'document_type' => $extracted->documentType,
                'summary' => [
                    'lines_found' => count($extracted->lines),
                    'dates_unreadable' => $unreadable,
                    'detected_date_format' => $extracted->dateFormat,
                ],
                'completed_at' => now(),
            ]);

            return true;
        });

        if ($stored) {
            $import->refresh()->user->notify(new PdfImportReadyNotification($import));
            $this->index($import);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ExtractStatementPdfJob failed', [
            'pdfImportId' => $this->import->id,
            'userId' => $this->import->user_id,
            'exception' => $exception::class,
        ]);

        PdfImport::query()
            ->forUser($this->import->user_id)
            ->whereKey($this->import->id)
            ->whereIn('status', [PdfImportStatus::Pending, PdfImportStatus::Processing])
            ->update([
                'status' => PdfImportStatus::Failed,
                'failure_message' => __('The statement could not be processed.'),
                'completed_at' => now(),
            ]);
    }

    /**
     * @throws StatementExtractionFailed
     */
    private function pdfBytes(PdfImport $import): string
    {
        if (! config('budget.pdf_import.enabled')) {
            throw new StatementExtractionFailed('PDF import is not enabled.');
        }

        $statement = Statement::query()->where('user_id', $import->user_id)->find($import->statement_id);
        $disk = Storage::disk(Statement::DISK);

        if ($statement === null || ! $disk->exists($statement->stored_path)) {
            throw new StatementExtractionFailed('The PDF is no longer available.');
        }

        if ($disk->size($statement->stored_path) > (int) config('services.statement_ai.max_pdf_bytes')) {
            throw new StatementExtractionFailed('This PDF is too large for AI processing.');
        }

        return (string) $disk->get($statement->stored_path);
    }

    /**
     * Adds the analysed PDF to the user's document store when one is configured. A failure here
     * never fails the import.
     */
    private function index(PdfImport $import): void
    {
        if (! VectorStoreStatus::configured()) {
            return;
        }

        try {
            $statement = Statement::query()->where('user_id', $import->user_id)->find($import->statement_id);

            if ($statement === null || Document::query()->forUser($import->user_id)->where('stored_path', $statement->stored_path)->exists()) {
                return;
            }

            $document = Document::query()->create([
                'user_id' => $import->user_id,
                'filename' => $statement->original_filename,
                'mime_type' => 'application/pdf',
                'byte_size' => $statement->byte_size,
                'stored_path' => $statement->stored_path,
                'content_hash' => $statement->content_hash,
                'status' => DocumentStatus::Pending,
            ]);

            IndexDocumentJob::dispatch($document);
        } catch (Throwable $exception) {
            Log::warning('Statement PDF could not be queued for the document store', ['pdfImportId' => $import->id, 'exception' => $exception::class]);
        }
    }

    private function fail(PdfImport $import, string $message): void
    {
        $import->update([
            'status' => PdfImportStatus::Failed,
            'failure_message' => $message,
            'completed_at' => now(),
        ]);
    }
}
