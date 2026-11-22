<?php

declare(strict_types=1);

namespace App\Support\Documents;

/** The document store is on only when the OpenAI driver is chosen and its key is set. */
final class VectorStoreStatus
{
    public static function configured(): bool
    {
        return config('budget.documents.vector_store') === 'openai'
            && is_string(config('services.vector_store.openai.api_key'))
            && config('services.vector_store.openai.api_key') !== '';
    }
}
