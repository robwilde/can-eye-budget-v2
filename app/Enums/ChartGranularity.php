<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * How many points a dashboard chart plots over a range: monthly over five years, weekly
 * over one year, daily otherwise.
 */
enum ChartGranularity: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public static function resolve(CarbonInterface $start, CarbonInterface $end): self
    {
        $lastDay = $end->copy()->startOfDay();

        return match (true) {
            $start->copy()->addYears(5)->lessThan($lastDay) => self::Monthly,
            $start->copy()->addYear()->lessThan($lastDay) => self::Weekly,
            default => self::Daily,
        };
    }
}
