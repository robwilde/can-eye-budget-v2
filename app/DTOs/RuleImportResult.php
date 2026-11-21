<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

final class RuleImportResult extends Dto
{
    /**
     * @param  list<string>  $skipped  One line per rule left out, with the references that did not resolve
     */
    public function __construct(
        public readonly int $groupsCreated,
        public readonly int $rulesCreated,
        public readonly int $rulesUpdated,
        public readonly array $skipped,
    ) {}
}
