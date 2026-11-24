<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\DTOs\MatchScore;
use App\DTOs\PaymentCandidate;
use App\Models\PlannedMatchRejection;
use App\Models\PlannedOccurrence;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReconciliationPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The transactions a user can apply to one occurrence, best fit first: those that score under the rules
 * auto-matching uses (at most RANKED_LIMIT, with their signals), then the rest by how close their amount is
 * (at most FALLBACK_LIMIT). Only the user's own transactions on the plan's account, in its direction and
 * currency, with money left to apply, that no rejection ruled out; a search drops the date window.
 */
final class PaymentCandidateFinder
{
    public const int RANKED_LIMIT = 6;

    public const int FALLBACK_LIMIT = 15;

    public const int WINDOW_DAYS = 40;

    private const int POOL_LIMIT = 300;

    /** @return array{ranked: Collection<int, PaymentCandidate>, fallback: Collection<int, PaymentCandidate>} */
    public function find(User $user, PlannedOccurrence $occurrence, ?string $search = null): array
    {
        $plan = $occurrence->plannedTransaction;
        $today = $user->today();
        $due = CarbonImmutable::instance($occurrence->effectiveDueDate())->startOfDay();
        $expected = abs($occurrence->effectiveExpectedAmount());
        $term = $search === null ? '' : mb_trim($search);

        $base = fn (): Builder => Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->whereDoesntHave('splitChildren')
            ->where('account_id', $plan->account_id)
            ->where('direction', $plan->direction)
            ->where('currency', $plan->account->currency)
            ->whereRaw('ABS(amount) > COALESCE((SELECT SUM(planned_payments.amount) FROM planned_payments WHERE planned_payments.transaction_id = transactions.id), 0)')
            ->whereNotIn('id', PlannedMatchRejection::query()->where('user_id', $user->id)->where('planned_transaction_id', $plan->id)->select('transaction_id'))
            ->withSum('payments', 'amount')
            ->when($plan->transfer_to_account_id === null, fn (Builder $query): Builder => $query->excludingTransfers())
            ->when($plan->transfer_to_account_id !== null, fn (Builder $query): Builder => $query
                ->whereNotNull('transfer_pair_id')
                ->whereHas('transferPair', fn (Builder $pair): Builder => $pair->where('account_id', $plan->transfer_to_account_id)))
            ->when($term !== '', fn (Builder $query): Builder => $query->where('description', 'like', '%'.addcslashes($term, '%_\\').'%'));

        $wideFrom = $due->subDays(self::WINDOW_DAYS)->toDateString();
        $wideTo = min($due->addDays(self::WINDOW_DAYS), $today)->toDateString();
        $scoreTo = min($due->addDays(max($plan->match_late_days, $due->lessThan($today) ? (int) $due->diffInDays($today) : 0)), $today)->toDateString();

        $scored = $base()
            ->when($term === '', fn (Builder $query): Builder => $query
                ->whereDate('post_date', '>=', $due->subDays($plan->match_early_days)->toDateString())
                ->whereDate('post_date', '<=', $scoreTo))
            ->orderByDesc('post_date')
            ->orderByDesc('id')
            ->limit(self::POOL_LIMIT)
            ->get();

        $closest = $base()
            ->when($term === '', fn (Builder $query): Builder => $query
                ->whereDate('post_date', '>=', $wideFrom)
                ->whereDate('post_date', '<=', $wideTo))
            ->orderByRaw('ABS(ABS(amount) - ?)', [$expected])
            ->orderByDesc('post_date')
            ->limit(self::RANKED_LIMIT + self::FALLBACK_LIMIT)
            ->get();

        $candidates = $scored->concat($closest)->unique('id')
            ->map(static function (Transaction $transaction) use ($occurrence, $today, $expected): PaymentCandidate {
                $free = abs($transaction->amount) - (int) $transaction->payments_sum_amount;

                return new PaymentCandidate($transaction, $free, ReconciliationPolicy::score($occurrence, $transaction, $today, $expected));
            })
            ->values();

        $ranked = $candidates
            ->filter(static fn (PaymentCandidate $candidate): bool => $candidate->score instanceof MatchScore)
            ->sortByDesc(static fn (PaymentCandidate $candidate): float => $candidate->score->total())
            ->take(self::RANKED_LIMIT)
            ->values();

        $rankedIds = $ranked->map(static fn (PaymentCandidate $candidate): int => $candidate->transaction->id)->flip();

        $fallback = $candidates
            ->reject(static fn (PaymentCandidate $candidate): bool => $rankedIds->has($candidate->transaction->id))
            ->sortBy(static fn (PaymentCandidate $candidate): int => abs(abs($candidate->transaction->amount) - $expected))
            ->take(self::FALLBACK_LIMIT)
            ->values();

        return ['ranked' => $ranked, 'fallback' => $fallback];
    }
}
