<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

use App\Enums\TransactionDirection;
use Carbon\CarbonImmutable;

final readonly class ParsedTransactionDto
{
    /**
     * @param  array<string, string>  $rawRow
     */
    public function __construct(
        public CarbonImmutable $postDate,
        public int $amount,
        public TransactionDirection $direction,
        public string $description,
        public ?int $balance,
        public array $rawRow,
        public string $csvHash,
    ) {}
}
