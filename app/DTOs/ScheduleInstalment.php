<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

final class ScheduleInstalment extends Dto
{
    /**
     * @param  int  $amount  positive, in cents
     */
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly int $amount,
    ) {}

    /**
     * @return array{date: string, amount: int}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date->format('Y-m-d'),
            'amount' => $this->amount,
        ];
    }
}
