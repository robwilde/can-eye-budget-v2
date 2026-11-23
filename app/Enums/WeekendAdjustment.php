<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

enum WeekendAdjustment: string
{
    case None = 'none';
    case Skip = 'skip';
    case Before = 'before';
    case After = 'after';

    /**
     * The due date for a scheduled date: the date itself on a weekday or with no adjustment,
     * the nearest weekday before or after it, or null when weekend dates are skipped.
     */
    public function adjust(CarbonImmutable $date): ?CarbonImmutable
    {
        if ($this === self::None || ! $date->isWeekend()) {
            return $date;
        }

        return match ($this) {
            self::Skip => null,
            self::Before => $date->previousWeekday(),
            self::After => $date->nextWeekday(),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::None => __('Leave on the weekend'),
            self::Skip => __('Skip weekends'),
            self::Before => __('Move before the weekend'),
            self::After => __('Move after the weekend'),
        };
    }
}
