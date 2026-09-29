<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransferLinkSource;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Transfers\TransferSignal;
use Illuminate\Database\Eloquent\Builder;

/**
 * What still needs a decision: pending suggested pairs and Transfer-categorised rows
 * with no match. One definition shared by the review page, the list filter/badge and
 * the dashboard prompt so they can never disagree.
 */
final class TransferReviewQueue
{
    /**
     * One row (the debit leg) per pending suggested pair.
     *
     * @return Builder<Transaction>
     */
    public function suggestedPairs(User $user): Builder
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->possibleTransfer()
            ->where('direction', TransactionDirection::Debit);
    }

    /**
     * Bank-feed rows on tracked accounts that carry the transfer signal (description mentions
     * "transfer" or the row is categorised under Transfer) but have no link, no pending
     * suggestion and were not rejected: the same signal and sources detection uses, so a
     * leg whose partner never arrived can still be dismissed or linked to a hidden account.
     *
     * @return Builder<Transaction>
     */
    public function unmatched(User $user): Builder
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->whereIn('source', array_map(fn (TransactionSource $s): string => $s->value, TransactionSource::bankFeed()))
            ->whereNull('transfer_pair_id')
            ->whereNull('suggested_pair_id')
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('transfer_link_source')
                ->orWhere('transfer_link_source', '!=', TransferLinkSource::Unlinked->value))
            ->whereHas('account', fn (Builder $a): Builder => $a->where('is_tracked', true))
            ->tap(TransferSignal::whereCandidate(...));
    }

    public function pendingCount(User $user): int
    {
        return $this->suggestedPairs($user)->count() + $this->unmatched($user)->count();
    }
}
