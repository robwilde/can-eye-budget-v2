<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GoalPledgeStatus;
use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Models\Goal;
use App\Models\GoalPledge;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Matches a user's open pledges to the incoming transaction they describe. An inflow on the
 * pledged account matches when it is dated from five days before the pledge to the later of
 * five days after it or its expiry, and its amount is within the larger of 50 cents or 1% of
 * the pledge. Outflows never match, and a transaction is claimed by one pledge only. Reads and
 * writes are limited to the user's own goals and transactions.
 */
final class GoalPledgeMatcher
{
    public const int WINDOW_DAYS = 5;

    public const int MIN_TOLERANCE_CENTS = 50;

    /**
     * Returns how many open pledges were matched.
     *
     * @throws Throwable
     */
    public function matchForUser(User $user): int
    {
        return GoalPledge::resolveConnection()->transaction(function () use ($user): int {
            $pledges = GoalPledge::query()
                ->whereIn('goal_id', Goal::query()->where('user_id', $user->id)->select('id'))
                ->where('status', GoalPledgeStatus::Open)
                ->where('expires_at', '>', now())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $claimed = [];
            $matched = 0;

            foreach ($pledges as $pledge) {
                $transaction = $this->bestMatch($user, $pledge, $claimed);

                if ($transaction === null) {
                    continue;
                }

                $pledge->update([
                    'status' => GoalPledgeStatus::Matched,
                    'matched_transaction_id' => $transaction->id,
                ]);
                $claimed[] = $transaction->id;
                $matched++;
            }

            return $matched;
        });
    }

    /**
     * @param  list<int>  $claimed
     */
    private function bestMatch(User $user, GoalPledge $pledge, array $claimed): ?Transaction
    {
        $from = $pledge->created_at->subDays(self::WINDOW_DAYS)->startOfDay();
        $until = max($pledge->created_at->addDays(self::WINDOW_DAYS), $pledge->expires_at)->endOfDay();

        $candidates = Transaction::query()
            ->where('user_id', $user->id)
            ->where('account_id', $pledge->account_id)
            ->current()
            ->where('direction', TransactionDirection::Credit)
            ->where('status', TransactionStatus::Posted)
            ->where('excluded', false)
            ->whereNull('split_parent_id')
            ->where('post_date', '>=', $from)
            ->where('post_date', '<=', $until)
            ->whereNotIn('id', $claimed)
            ->orderBy('post_date')
            ->orderBy('id')
            ->get();

        $tolerance = max(self::MIN_TOLERANCE_CENTS, intdiv($pledge->amount, 100));
        $fitting = $candidates->filter(fn (Transaction $transaction): bool => abs(abs($transaction->amount) - $pledge->amount) <= $tolerance);

        return $this->unclaimed($user, $fitting)
            ->sortBy(fn (Transaction $transaction): int => abs(abs($transaction->amount) - $pledge->amount))
            ->first();
    }

    /**
     * Drops candidates already claimed by a pledge, directly or through any other revision of
     * the same transaction.
     *
     * @param  Collection<int, Transaction>  $candidates
     * @return Collection<int, Transaction>
     */
    private function unclaimed(User $user, Collection $candidates): Collection
    {
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $lineages = $candidates->mapWithKeys(fn (Transaction $transaction): array => [$transaction->id => $this->lineage($user, $transaction)]);

        $taken = GoalPledge::query()
            ->whereIn('matched_transaction_id', $lineages->flatten()->unique()->all())
            ->pluck('matched_transaction_id')
            ->all();

        return $candidates->reject(fn (Transaction $transaction): bool => array_intersect($lineages[$transaction->id], $taken) !== []);
    }

    /**
     * Every revision of the transaction, earlier, later and soft-deleted: an edit creates a new
     * row, and deleting it makes its predecessor current again.
     *
     * @return list<int>
     */
    private function lineage(User $user, Transaction $transaction): array
    {
        $ids = [$transaction->id];
        $parentId = $transaction->parent_transaction_id;

        while ($parentId !== null && ! in_array($parentId, $ids, true)) {
            $ids[] = $parentId;
            $parentId = Transaction::withTrashed()
                ->where('user_id', $user->id)
                ->whereKey($parentId)
                ->value('parent_transaction_id');
        }

        $frontier = $ids;

        while ($frontier !== []) {
            $children = Transaction::withTrashed()
                ->where('user_id', $user->id)
                ->whereIn('parent_transaction_id', $frontier)
                ->pluck('id')
                ->all();

            $frontier = array_values(array_diff($children, $ids));
            array_push($ids, ...$frontier);
        }

        return $ids;
    }
}
