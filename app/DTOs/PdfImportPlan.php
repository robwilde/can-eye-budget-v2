<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\Transaction;
use Spatie\LaravelData\Dto;

/**
 * What publishing a PDF import to an account would do, computed without saving anything.
 */
final class PdfImportPlan extends Dto
{
    /**
     * @param  array<int, Transaction>  $matched  line id => the existing transaction it matches
     * @param  list<int>  $statementOnly  ids of lines that would become new transactions
     * @param  list<int>  $waiting  ids of lines held back because their date could not be read
     */
    public function __construct(
        public readonly array $matched,
        public readonly array $statementOnly,
        public readonly array $waiting,
        public readonly int $feedOnly,
    ) {}
}
