<?php

declare(strict_types=1);

namespace App\Services\Documents;

final readonly class DocumentSearchResult
{
    /**
     * @param  list<DocumentExcerptDto>  $excerpts
     */
    public function __construct(
        public array $excerpts,
        public ?string $message = null,
    ) {}
}
