<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

final class ScheduledDate extends Dto
{
    public function __construct(
        public readonly CarbonImmutable $original,
        public readonly CarbonImmutable $due,
    ) {}
}
