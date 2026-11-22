<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use App\Enums\ChartGranularity;
use App\Enums\TransactionPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The dashboard period every period-driven widget reads: the user's saved choice as a concrete
 * first and last day plus the chart granularity for that range. An unknown saved key, or a custom
 * pair that is missing, invalid or backwards, resolves to the last 30 days.
 */
final readonly class DashboardPeriod
{
    public function __construct(
        public TransactionPeriod $period,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public ChartGranularity $granularity,
    ) {}

    public static function for(User $user): self
    {
        $preferences = $user->preferences;
        $period = $preferences->dashboardPeriod;

        if ($period === TransactionPeriod::Custom && ! TransactionPeriod::validRange($preferences->dashboardFrom, $preferences->dashboardTo)) {
            $period = TransactionPeriod::ThirtyDays;
        }

        $range = $period->resolve($user, $preferences->dashboardFrom, $preferences->dashboardTo);

        return new self($period, $range['start'], $range['end'], ChartGranularity::resolve($range['start'], $range['end']));
    }

    public function label(): string
    {
        return $this->period === TransactionPeriod::Custom
            ? $this->start->format('j M Y').' to '.$this->end->format('j M Y')
            : $this->period->label();
    }

    /** Changes whenever the selected period or its range does; a widget takes it as a reactive prop. */
    public function stamp(): string
    {
        return $this->period->value.'|'.$this->start->toDateString().'|'.$this->end->toDateString();
    }
}
