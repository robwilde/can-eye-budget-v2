<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\StatementCoverageStatus;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

final class StatementCoverageMonth extends Dto
{
    /**
     * @param  list<int>  $statementIds  linked statements whose period touches the month
     */
    public function __construct(
        public readonly CarbonImmutable $month,
        public readonly StatementCoverageStatus $status,
        public readonly array $statementIds = [],
    ) {}
}
