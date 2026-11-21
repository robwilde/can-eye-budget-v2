<?php

declare(strict_types=1);

namespace App\Services\Statement;

use App\DTOs\StatementCoverageMonth;
use App\Enums\StatementCoverageStatus;
use App\Enums\StatementReviewStatus;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\Statement;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which months of a calendar year an account's statements cover. A statement covers every
 * month its period touches.
 */
final class StatementCoverageService
{
    private const int DEFAULT_MONTHS = 12;

    public function __construct(private readonly StatementBalanceChecker $checker) {}

    /**
     * @return list<StatementCoverageMonth>
     */
    public function forAccount(Account $account, int $year): array
    {
        $linked = $this->periodStatements($account, StatementReviewStatus::Linked);
        $suggested = $this->periodStatements($account, StatementReviewStatus::Unmatched);
        [$expectedStart, $expectedEnd] = $this->expectedRange($account, $linked, $suggested);

        $yearStart = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $yearEnd = $yearStart->endOfYear();

        $mismatched = $linked
            ->filter(fn (Statement $statement): bool => $statement->period_start <= $yearEnd && $statement->period_end >= $yearStart)
            ->filter(fn (Statement $statement): bool => $this->checker->check($statement)->isMismatch());

        $months = [];

        for ($number = 1; $number <= 12; $number++) {
            $month = CarbonImmutable::create($year, $number, 1)->startOfDay();
            $covering = $linked->filter(fn (Statement $statement): bool => $this->touches($statement, $month))->values();

            $months[] = new StatementCoverageMonth(
                $month,
                $this->status($month, $covering, $suggested, $mismatched, $expectedStart, $expectedEnd),
                $covering->pluck('id')->all(),
            );
        }

        return $months;
    }

    /**
     * The first and last years that hold something worth showing, for the year selector.
     *
     * @return array{0: int, 1: int}
     */
    public function yearRange(Account $account): array
    {
        [$start, $end] = $this->expectedRange(
            $account,
            $this->periodStatements($account, StatementReviewStatus::Linked),
            $this->periodStatements($account, StatementReviewStatus::Unmatched),
        );

        return [min($start->year, $end->year), max($end->year, $account->user->today()->year)];
    }

    /**
     * @param  Collection<int, Statement>  $covering
     * @param  Collection<int, Statement>  $suggested
     * @param  Collection<int, Statement>  $mismatched
     */
    private function status(CarbonImmutable $month, Collection $covering, Collection $suggested, Collection $mismatched, CarbonImmutable $expectedStart, CarbonImmutable $expectedEnd): StatementCoverageStatus
    {
        if ($covering->isNotEmpty()) {
            return match (true) {
                $covering->contains(fn (Statement $statement): bool => $mismatched->contains('id', $statement->id)) => StatementCoverageStatus::Mismatched,
                $this->anyOverlap($covering) => StatementCoverageStatus::Duplicate,
                default => StatementCoverageStatus::Covered,
            };
        }

        if ($suggested->contains(fn (Statement $statement): bool => $this->touches($statement, $month))) {
            return StatementCoverageStatus::Ambiguous;
        }

        return $month >= $expectedStart && $month <= $expectedEnd ? StatementCoverageStatus::Missing : StatementCoverageStatus::NotExpected;
    }

    /**
     * @param  Collection<int, Statement>  $statements
     */
    private function anyOverlap(Collection $statements): bool
    {
        $list = $statements->values();

        foreach ($list as $i => $a) {
            foreach ($list->slice($i + 1) as $b) {
                if ($a->period_start <= $b->period_end && $b->period_start <= $a->period_end) {
                    return true;
                }
            }
        }

        return false;
    }

    private function touches(Statement $statement, CarbonImmutable $month): bool
    {
        return $statement->period_start <= $month->endOfMonth() && $statement->period_end >= $month;
    }

    /**
     * @param  Collection<int, Statement>  $linked
     * @param  Collection<int, Statement>  $suggested
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function expectedRange(Account $account, Collection $linked, Collection $suggested): array
    {
        $lastMonth = $account->user->today()->startOfMonth()->subMonth();

        $starts = array_filter([
            $this->date(Transaction::query()->where('user_id', $account->user_id)->where('account_id', $account->id)->current()->min('post_date')),
            $this->date(AccountDailyBalance::query()->forUser($account->user_id)->where('account_id', $account->id)->min('balance_date')),
            $linked->min('period_start'),
            $suggested->min('period_start'),
        ]);

        if ($starts === []) {
            return [$lastMonth->subMonths(self::DEFAULT_MONTHS - 1), $lastMonth];
        }

        $earliest = min($starts)->startOfMonth();

        return [$earliest, $lastMonth];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->startOfDay();
    }

    /**
     * @return Collection<int, Statement>
     */
    private function periodStatements(Account $account, StatementReviewStatus $status): Collection
    {
        $query = Statement::query()
            ->where('user_id', $account->user_id)
            ->where('review_status', $status)
            ->whereNotNull('period_start')
            ->whereNotNull('period_end');

        return $status === StatementReviewStatus::Linked
            ? $query->where('account_id', $account->id)->get()
            : $query->where('suggested_account_id', $account->id)->get();
    }
}
