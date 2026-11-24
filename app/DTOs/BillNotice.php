<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Dto;

/** One notice on the Bills overview: a trial ending, a renewal or a recent price change, urgent or not. */
final class BillNotice extends Dto
{
    public const string TRIAL = 'trial';

    public const string RENEWAL = 'renewal';

    public const string PRICE_CHANGE = 'price_change';

    public function __construct(
        public readonly string $kind,
        public readonly int $planId,
        public readonly string $name,
        public readonly CarbonImmutable $date,
        public readonly bool $urgent,
        public readonly bool $material,
        public readonly ?int $previousAmount,
        public readonly ?int $newAmount,
    ) {}
}
