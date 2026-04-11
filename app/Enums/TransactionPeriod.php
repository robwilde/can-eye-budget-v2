<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Exception;

enum TransactionPeriod: string
{
    case SevenDays = '7d';
    case ThisMonth = 'this-month';
    case ThreeMonths = '3m';
    case SixMonths = '6m';
    case OneYear = '1y';
    case PayCycle = 'pay-cycle';
    case All = 'all';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::SevenDays => 'Last 7 Days',
            self::ThisMonth => 'This Month',
            self::ThreeMonths => 'Last 3 Months',
            self::SixMonths => 'Last 6 Months',
            self::OneYear => 'Last Year',
            self::PayCycle => 'Pay Cycle',
            self::All => 'All Time',
            self::Custom => 'Custom Range',
        };
    }

    /**
     * @return array{start: ?CarbonInterface, end: ?CarbonInterface}
     */
    public function dateRange(?User $user = null, ?string $from = null, ?string $to = null): array
    {
        return match ($this) {
            self::SevenDays => ['start' => now()->subDays(7)->startOfDay(), 'end' => null],
            self::ThisMonth => ['start' => now()->startOfMonth(), 'end' => null],
            self::ThreeMonths => ['start' => now()->subMonths(3)->startOfDay(), 'end' => null],
            self::SixMonths => ['start' => now()->subMonths(6)->startOfDay(), 'end' => null],
            self::OneYear => ['start' => now()->subYear()->startOfDay(), 'end' => null],
            self::PayCycle => $this->payCycleDateRange($user),
            self::All => ['start' => null, 'end' => null],
            self::Custom => $this->customDateRange($from, $to),
        };
    }

    /**
     * @return array{start: ?CarbonInterface, end: ?CarbonInterface}
     */
    private function payCycleDateRange(?User $user): array
    {
        if (! $user) {
            return self::ThisMonth->dateRange();
        }

        $bounds = $user->currentPayCycleBounds();

        if (! $bounds) {
            return self::ThisMonth->dateRange();
        }

        return [
            'start' => $bounds['start']->startOfDay(),
            'end' => $bounds['end']->endOfDay(),
        ];
    }

    /**
     * @return array{start: ?CarbonInterface, end: ?CarbonInterface}
     */
    private function customDateRange(?string $from, ?string $to): array
    {
        try {
            $start = $from ? CarbonImmutable::parse($from)->startOfDay() : null;
        } catch (Exception) {
            $start = null;
        }

        try {
            $end = $to ? CarbonImmutable::parse($to)->endOfDay() : null;
        } catch (Exception) {
            $end = null;
        }

        if (! $start && ! $end) {
            return self::ThisMonth->dateRange();
        }

        if ($start && $end && $start->greaterThan($end)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        return ['start' => $start, 'end' => $end];
    }
}
