<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Exceptions\DocumentRefused;
use App\Models\Document;
use App\Models\User;
use App\Support\Documents\VectorStoreStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Accepts a user's document for the active vector store: it checks the store is configured, the
 * size and the file type, then keeps the file on the local disk and records it as pending.
 */
final class DocumentUploadService
{
    public const int MAX_BYTES = 25 * 1024 * 1024;

    /**
     * @throws DocumentRefused with a message safe to show the user
     */
    public function store(User $user, UploadedFile $file): Document
    {
        $driver = config('budget.documents.vector_store');

        if (! VectorStoreStatus::configured() || ! is_string($driver)) {
            throw new DocumentRefused(__('No vector store is configured'));
        }

        if ((int) $file->getSize() > self::MAX_BYTES) {
            throw new DocumentRefused(__('Documents can be at most 25 MB.'));
        }

        $accepted = (array) config('budget.documents.accepted_types.'.$driver, []);
        $extension = mb_strtolower($file->getClientOriginalExtension());

        if ($extension === '' || ! in_array($extension, $accepted, true)) {
            throw new DocumentRefused(__('Invalid document file type for the active vector store'));
        }

        $path = $file->store('documents', 'local');

        if ($path === false) {
            throw new DocumentRefused(__('The document could not be stored.'));
        }

        try {
            return $this->record($user, $file, $path);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
    }

    private function record(User $user, UploadedFile $file, string $path): Document
    {
        return Document::query()->create([
            'user_id' => $user->id,
            'filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => mb_substr((string) $file->getMimeType(), 0, 150),
            'byte_size' => (int) $file->getSize(),
            'stored_path' => $path,
            'content_hash' => hash_file('sha256', Storage::disk('local')->path($path)),
            'status' => DocumentStatus::Pending,
        ]);
    }
}
