<?php

declare(strict_types=1);

namespace App\Support\Redbark;

use Carbon\CarbonImmutable;

/**
 * The first pull for a newly linked Redbark account covers the last full calendar
 * month up to today: enough to seed recent spending without a quarter of history.
 */
final class InitialSyncWindow
{
    public static function start(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subMonthNoOverflow()->startOfMonth();
    }
}
