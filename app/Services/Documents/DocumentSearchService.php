<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Contracts\VectorStoreContract;
use App\Exceptions\VectorStoreException;
use App\Models\Document;
use App\Models\User;

/**
 * Searches one user's own documents. Only the vector store id kept on that user is queried.
 */
final readonly class DocumentSearchService
{
    public const int MAX_LIMIT = 20;

    public function __construct(private VectorStoreContract $store) {}

    /**
     * @throws VectorStoreException
     */
    public function search(User $user, string $query, int $limit = 10): DocumentSearchResult
    {
        $storeId = User::query()->whereKey($user->id)->value('vector_store_id');

        if (! Document::query()->forUser($user)->exists() || ! is_string($storeId)) {
            return new DocumentSearchResult([], __('No documents have been uploaded to the document store yet'));
        }

        $hits = $this->store->search($storeId, $query, max(1, min(self::MAX_LIMIT, $limit)));

        if ($hits === []) {
            return new DocumentSearchResult([], __('No matching documents found'));
        }

        return new DocumentSearchResult(array_map(
            fn (array $hit): DocumentExcerptDto => new DocumentExcerptDto($hit['filename'], $hit['excerpt'], $hit['score']),
            $hits,
        ));
    }
}
