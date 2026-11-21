<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\Statement;
use Spatie\LaravelData\Dto;

/**
 * The outcome of one upload batch: the statements filed, how many files were already in
 * the vault, and one message per file that was rejected.
 */
final class StatementUploadResult extends Dto
{
    /**
     * @param  list<Statement>  $created
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly array $created = [],
        public readonly int $duplicates = 0,
        public readonly array $errors = [],
    ) {}

    /**
     * Joins the per-file errors into one message, or null when every file was accepted.
     */
    public function errorMessage(): ?string
    {
        return $this->errors === [] ? null : implode(' ', $this->errors);
    }
}
