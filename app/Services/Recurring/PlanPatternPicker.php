<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\DTOs\PlanPattern;
use App\DTOs\RecurringCandidate;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The recurring patterns the detector found that the user has not declared, for the add-bill and
 * add-income forms. Candidates the suggestion writer would skip (noise, covered by a plan, accepted or
 * rejected) never appear, so the picker and the suggestions cannot disagree.
 */
final readonly class PlanPatternPicker
{
    public const int LIMIT = 8;

    public const int MIN_AMOUNT_CENTS = 100;

    public const int MIN_CHARGES = 2;

    public const int INCOME_WINDOW_DAYS = 90;

    public function __construct(
        private RecurringTransactionDetector $detector,
        private RecurringSuggestionWriter $writer,
    ) {}

    /**
     * Up to LIMIT patterns of the direction: bills with at least two charges, income with at least two
     * deposits in the last 90 days and the largest source first.
     *
     * @return Collection<int, PlanPattern>
     */
    public function patterns(User $user, TransactionDirection $direction): Collection
    {
        $today = $user->today();

        $reachable = Account::query()->where('user_id', $user->id)->active()->tracked()->pluck('id')->all();

        $candidates = $this->detector->detect($user)
            ->filter(fn (RecurringCandidate $candidate): bool => $candidate->direction === $direction
                && abs($candidate->amount) >= self::MIN_AMOUNT_CENTS
                && in_array($candidate->accountId, $reachable, true)
                && $this->writer->skipReason($user, $candidate) === null)
            ->values();

        if ($candidates->isEmpty()) {
            return collect();
        }

        $dates = Transaction::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $candidates->flatMap(static fn (RecurringCandidate $candidate): array => $candidate->matchedTransactionIds)->all())
            ->pluck('post_date', 'id');

        $windowStart = $today->subDays(self::INCOME_WINDOW_DAYS)->startOfDay();

        $patterns = $candidates
            ->map(function (RecurringCandidate $candidate) use ($dates): PlanPattern {
                $posted = collect($candidate->matchedTransactionIds)->map(fn (int $id) => $dates[$id] ?? null)->filter();

                return new PlanPattern(
                    key: hash('sha256', $candidate->description.'|'.$candidate->direction->value.'|'.$candidate->accountId),
                    candidate: $candidate,
                    charges: $posted->count(),
                    latestDate: $posted->max()?->toImmutable() ?? $candidate->startDate,
                );
            })
            ->filter(fn (PlanPattern $pattern): bool => $direction === TransactionDirection::Credit
                ? collect($pattern->candidate->matchedTransactionIds)
                    ->filter(fn (int $id): bool => isset($dates[$id]) && $dates[$id]->greaterThanOrEqualTo($windowStart))
                    ->count() >= self::MIN_CHARGES
                : $pattern->charges >= self::MIN_CHARGES);

        return ($direction === TransactionDirection::Credit
            ? $patterns->sortByDesc(fn (PlanPattern $pattern): array => [abs($pattern->candidate->amount), $pattern->candidate->confidenceScore])
            : $patterns->sortByDesc(fn (PlanPattern $pattern): array => [$pattern->candidate->confidenceScore, abs($pattern->candidate->amount)]))
            ->take(self::LIMIT)
            ->values();
    }
}
