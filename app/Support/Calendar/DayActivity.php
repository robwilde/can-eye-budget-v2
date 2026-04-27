<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Livewire\Dashboard\Data\PayCyclePip;

final readonly class DayActivity
{
    /**
     * @param  list<PayCyclePip>  $pips  Sorted by amount desc.
     */
    public function __construct(
        public array $pips,
        public int $incomeCents,
        public int $postedCents,
        public int $plannedCents,
    ) {}

    public static function empty(): self
    {
        return new self(pips: [], incomeCents: 0, postedCents: 0, plannedCents: 0);
    }
}
