<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

/**
 * What the import screen and the API read from a CSV before importing it: its headers and the column and
 * format mapping detected for the account and preset.
 */
final readonly class CsvPreparation
{
    /**
     * @param  list<string>  $headers
     * @param  array<string, string|null>  $mapping
     */
    public function __construct(
        public array $headers,
        public array $mapping,
    ) {}
}
