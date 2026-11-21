<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\StatementLineKind;
use App\Models\Transaction;
use App\Services\CsvImport\ParsedTransactionDto;
use Spatie\LaravelData\Dto;

/**
 * One result of matching statement lines to an account's transactions: a line and the
 * transaction it matches, a line with no match, or a transaction the statement does not show.
 */
final class StatementLineMatchEntry extends Dto
{
    public function __construct(
        public readonly StatementLineKind $kind,
        public readonly ?ParsedTransactionDto $line = null,
        public readonly ?Transaction $transaction = null,
    ) {}
}
