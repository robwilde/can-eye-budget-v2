<?php

declare(strict_types=1);

namespace App\Services\Documents;

final readonly class DocumentExcerptDto
{
    public function __construct(
        public string $filename,
        public string $excerpt,
        public float $score,
    ) {}
}
