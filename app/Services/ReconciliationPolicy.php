<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * The single source of truth for deciding whether a posted transaction fulfils a
 * planned-transaction occurrence. Ingress (TransactionIngestor), the async backstop
 * (PlannedTransactionMatcher) and the calendar dedup all reconcile through these rules,
 * so a match made at import time and the pip the calendar renders never disagree.
 *
 * A candidate matches when it shares the plan's account and direction, its amount sits
 * within AMOUNT_TOLERANCE of the plan amount (covering variable bills), and its post date
 * is within DATE_TOLERANCE_DAYS of an occurrence.
 */
final class ReconciliationPolicy
{
    /** Fraction the transaction amount may deviate from the plan amount and still match. */
    public const float AMOUNT_TOLERANCE = 0.10;

    /** Days a transaction's post date may sit either side of a plan occurrence. */
    public const int DATE_TOLERANCE_DAYS = 3;

    public static function amountMatches(int $transactionAmount, int $planAmount): bool
    {
        [$min, $max] = self::amountRange($planAmount);

        $abs = abs($transactionAmount);

        return $abs >= $min && $abs <= $max;
    }

    /**
     * Inclusive [min, max] absolute-cent bounds a transaction amount may fall in to match
     * the given plan amount. Used to build SQL `ABS(amount) BETWEEN ?` constraints.
     *
     * @return array{0: int, 1: int}
     */
    public static function amountRange(int $planAmount): array
    {
        $abs = abs($planAmount);
        $delta = (int) round($abs * self::AMOUNT_TOLERANCE);

        return [$abs - $delta, $abs + $delta];
    }

    public static function datesMatch(CarbonImmutable $postDate, CarbonImmutable $occurrence): bool
    {
        return self::dayDiff($postDate, $occurrence) <= self::DATE_TOLERANCE_DAYS;
    }

    public static function dayDiff(CarbonImmutable $postDate, CarbonImmutable $occurrence): int
    {
        return (int) abs($postDate->diffInDays($occurrence));
    }
}
