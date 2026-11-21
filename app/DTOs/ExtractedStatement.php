<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\StatementDocumentType;
use Spatie\LaravelData\Dto;

final class ExtractedStatement extends Dto
{
    /**
     * @param  list<ExtractedStatementLine>  $lines
     */
    public function __construct(
        public readonly StatementDocumentType $documentType,
        public readonly ?string $dateFormat,
        public readonly array $lines,
    ) {}
}
