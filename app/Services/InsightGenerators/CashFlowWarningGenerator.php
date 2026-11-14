<?php

declare(strict_types=1);

namespace App\Services\InsightGenerators;

use App\Contracts\InsightGenerator;
use App\Enums\InsightPriority;
use App\Enums\InsightType;
use App\Models\User;
use App\Services\InsightRecorder;
use App\Services\Projection\BalancePoint;
use App\Services\Projection\MonthlyProjectionService;
use App\Support\Insights\InsightMoney;

final readonly class CashFlowWarningGenerator implements InsightGenerator
{
    private const int DAYS_AHEAD = 30;

    private const int WARNING_BELOW_CENTS = 50000;

    private const string DEDUP_KEY = 'cash_flow_warning';

    public function __construct(
        private InsightRecorder $recorder,
        private MonthlyProjectionService $projection,
    ) {}

    public function key(): string
    {
        return 'cash-flow-warning';
    }

    public function types(): array
    {
        return [InsightType::CashFlowWarning];
    }

    public function generate(User $user): array
    {
        $projection = $this->projection->forUser($user, 2);

        if ($projection === null) {
            return [];
        }

        $horizon = $projection->startsAt->addDays(self::DAYS_AHEAD);
        $lowest = null;

        foreach ($projection->points as $point) {
            if ($point->date->lessThanOrEqualTo($horizon) && ($lowest === null || $point->balanceCents < $lowest->balanceCents)) {
                $lowest = $point;
            }
        }

        if (! $lowest instanceof BalancePoint || $lowest->balanceCents >= self::WARNING_BELOW_CENTS) {
            return [];
        }

        $negative = $lowest->balanceCents < 0;
        $currency = $user->primaryAccount->currency;

        $this->recorder->record(
            user: $user,
            type: InsightType::CashFlowWarning,
            priority: $negative ? InsightPriority::High : InsightPriority::Medium,
            dedupKey: self::DEDUP_KEY,
            title: $negative ? 'Your balance is projected to go below zero' : 'Your balance is projected to run low',
            body: sprintf(
                'Over the next %d days your balance is projected to drop to %s on %s.',
                self::DAYS_AHEAD,
                InsightMoney::format($lowest->balanceCents, $currency),
                $lowest->date->format('j M'),
            ),
            figures: [
                'lowest_balance_cents' => $lowest->balanceCents,
                'currency' => $currency,
                'lowest_date' => $lowest->date->toDateString(),
                'days_ahead' => self::DAYS_AHEAD,
            ],
            comparison: [
                'severity' => $negative ? 'below_zero' : 'low',
                'lowest_balance_band' => intdiv($lowest->balanceCents, 10000),
            ],
            actionRoute: 'dashboard',
        );

        return [self::DEDUP_KEY];
    }
}
