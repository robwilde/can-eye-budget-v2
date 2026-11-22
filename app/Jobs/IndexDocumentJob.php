<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\VectorStoreContract;
use App\Enums\DocumentStatus;
use App\Exceptions\VectorStoreException;
use App\Models\Document;
use App\Services\Documents\DocumentContentSniffer;
use App\Services\Documents\UserVectorStore;
use App\Support\Audit\AttributeJobToUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sends one pending document to the user's vector store. The bytes are checked first, so a file
 * that is not what its extension says never leaves the app. A permanent refusal (bad credentials,
 * a rejected file) marks the document as error; a timeout or server error is retried.
 */
final class IndexDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly Document $document) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new AttributeJobToUser($this->document->user_id),
            new WithoutOverlapping("index-document-{$this->document->id}")->expireAfter(300)->dontRelease(),
        ];
    }

    /**
     * @throws VectorStoreException
     */
    public function handle(VectorStoreContract $store, UserVectorStore $stores, DocumentContentSniffer $sniffer): void
    {
        $document = Document::query()->forUser($this->document->user_id)->find($this->document->id);

        if ($document === null || ! in_array($document->status, [DocumentStatus::Pending, DocumentStatus::Processing], true)) {
            return;
        }

        $document->update(['status' => DocumentStatus::Processing, 'error_message' => null]);
        $disk = Storage::disk('local');

        if (! $disk->exists($document->stored_path)) {
            $this->error($document, __('The document is no longer available.'));

            return;
        }

        if (! $sniffer->matches(pathinfo($document->filename, PATHINFO_EXTENSION), $disk->path($document->stored_path))) {
            $this->error($document, __('The file content does not match its type.'));

            return;
        }

        try {
            $storeId = $stores->idFor($document->user);
            $fileId = $store->addFile($storeId, $document->filename, (string) $disk->get($document->stored_path));
        } catch (VectorStoreException $exception) {
            if ($exception->permanent) {
                $this->error($document, $exception->getMessage());

                return;
            }

            throw $exception;
        }

        $document->update(['status' => DocumentStatus::Ready, 'vector_file_id' => $fileId]);
    }

    public function failed(Throwable $exception): void
    {
        Document::query()
            ->forUser($this->document->user_id)
            ->whereKey($this->document->id)
            ->whereIn('status', [DocumentStatus::Pending, DocumentStatus::Processing])
            ->update(['status' => DocumentStatus::Error, 'error_message' => $exception instanceof VectorStoreException ? $exception->getMessage() : __('The document could not be indexed.')]);
    }

    private function error(Document $document, string $message): void
    {
        $document->update(['status' => DocumentStatus::Error, 'error_message' => $message]);
    }
}
