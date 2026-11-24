<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\MatchScore;
use App\Enums\RecurrenceFrequency;
use App\Models\PlannedOccurrence;
use App\Models\Transaction;
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

    public const float AUTO_LINK_SCORE = 0.85;

    public const float SUGGEST_SCORE = 0.60;

    public const float AUTO_LINK_LEAD = 0.15;

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

    /**
     * Scores a transaction against one occurrence of its plan, or null when a hard filter fails: another
     * account or direction, no shared merchant key, description or learned alias, an amount outside the plan's
     * tolerance, or a post date outside the window (the plan's early and late days, each capped near half a
     * cycle; an overdue occurrence stays matchable up to $today). Compares sizes, so a refund never fits a debit.
     */
    public static function score(PlannedOccurrence $occurrence, Transaction $transaction, ?CarbonImmutable $today = null, ?int $expectedAmount = null): ?MatchScore
    {
        $plan = $occurrence->plannedTransaction;

        if ($transaction->account_id !== $plan->account_id || $transaction->direction !== $plan->direction) {
            return null;
        }

        $identity = self::identity($plan->merchant_key, $plan->description, $plan->learned_aliases, $transaction);

        if ($identity === null) {
            return null;
        }

        $expected = abs($expectedAmount ?? $occurrence->effectiveExpectedAmount());
        $paid = abs((int) $transaction->amount);

        if ($expected === 0 || abs($paid - $expected) * 10000 > $expected * $plan->amount_tolerance_bps) {
            return null;
        }

        $amount = $paid === $expected
            ? 0.30
            : round(0.25 - 0.10 * (abs($paid - $expected) * 10000 / ($expected * max(1, $plan->amount_tolerance_bps))), 4);

        $date = self::dateScore($occurrence, $transaction, $today ?? CarbonImmutable::today());

        return $date === null ? null : new MatchScore($identity, $amount, $date, 0.10);
    }

    /**
     * @param  array{descriptions?: list<string>, merchant_keys?: list<string>}|null  $aliases
     */
    private static function identity(?string $merchantKey, ?string $description, ?array $aliases, Transaction $transaction): ?float
    {
        if ($merchantKey !== null && $merchantKey !== '' && $merchantKey === $transaction->merchant_key) {
            return 0.40;
        }

        $text = self::normalise((string) $transaction->description);

        if ($transaction->merchant_key !== null && in_array($transaction->merchant_key, $aliases['merchant_keys'] ?? [], true)) {
            return 0.38;
        }

        if ($text !== '' && in_array($text, array_map(self::normalise(...), $aliases['descriptions'] ?? []), true)) {
            return 0.38;
        }

        $planText = self::normalise((string) $description);

        return $planText !== '' && $text !== '' && str_contains($text, $planText) ? 0.35 : null;
    }

    private static function normalise(string $text): string
    {
        return mb_strtolower(mb_trim((string) preg_replace('/[\s\h]+/u', ' ', $text)));
    }

    private static function dateScore(PlannedOccurrence $occurrence, Transaction $transaction, CarbonImmutable $today): ?float
    {
        $plan = $occurrence->plannedTransaction;
        $due = CarbonImmutable::instance($occurrence->snoozed_until ?? $occurrence->due_date)->startOfDay();
        $post = CarbonImmutable::instance($transaction->post_date)->startOfDay();
        $cap = max(1, intdiv(self::cycleDays($plan->frequency, $plan->recurrence_interval), 2));
        $early = min($plan->match_early_days, $cap);
        $late = min($plan->match_late_days, $cap);

        if ($due->lessThan($today)) {
            $late = max($late, (int) $due->diffInDays($today));
        }

        $offset = (int) $due->diffInDays($post, false);

        if ($offset < -$early || $offset > $late) {
            return null;
        }

        $reach = ($offset < 0 ? $early : $late) + 1;

        return round(0.20 * (1 - abs($offset) / $reach), 4);
    }

    private static function cycleDays(RecurrenceFrequency $frequency, int $interval): int
    {
        return match ($frequency) {
            RecurrenceFrequency::Semimonthly => 15,
            RecurrenceFrequency::EveryWorkday => 1,
            RecurrenceFrequency::EveryWeekend => 7,
            default => $frequency->dayStep($interval) ?? ($frequency->monthStep($interval) ?? 1) * 30,
        };
    }
}
