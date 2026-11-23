<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Enums\TransactionDirection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Finds the user's own transactions to start a plan from: by description, notes or merchant name, newest
 * first, with internal transfers and excluded rows left out (a payment to an untracked account is spending, so it stays).
 */
final class PlanTransactionSearch
{
    public const int PER_PAGE = 20;

    public const int MAX_TERM_LENGTH = 100;

    private const string LIKE_ESCAPE = '!';

    /**
     * @return LengthAwarePaginator<int, Transaction>
     */
    public function search(User $user, string $term, string $pageName = 'searchPage'): LengthAwarePaginator
    {
        $like = '%'.$this->escapeLike(mb_substr(mb_trim($term), 0, self::MAX_TERM_LENGTH)).'%';

        return $this->query($user)
            ->where(fn (Builder $query): Builder => $query
                ->whereRaw("description LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$like])
                ->orWhereRaw("notes LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$like])
                ->orWhereRaw("merchant_name LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$like]))
            ->with('plannedTransaction:id,description')
            ->orderByDesc('post_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, pageName: $pageName);
    }

    /**
     * The user's most recent charges that look like the description: same direction (and currency when
     * given), matching the description, the clean description or the merchant name.
     *
     * @return Collection<int, Transaction>
     */
    public function matching(User $user, string $description, TransactionDirection $direction, int $limit, ?string $currency = null): Collection
    {
        $term = mb_substr(mb_trim($description), 0, self::MAX_TERM_LENGTH);

        if ($term === '') {
            return new Collection;
        }

        $like = '%'.$this->escapeLike($term).'%';

        return $this->query($user)
            ->where('direction', $direction->value)
            ->when($currency !== null, fn (Builder $query): Builder => $query->where('currency', $currency))
            ->where(fn (Builder $query): Builder => $query
                ->whereRaw("description LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$like])
                ->orWhereRaw("clean_description LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$like])
                ->orWhereRaw("merchant_name LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$like]))
            ->orderByDesc('post_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** One of the user's searchable transactions by id; null for anything else. */
    public function find(User $user, int $id): ?Transaction
    {
        return $this->query($user)->whereKey($id)->first();
    }

    /** @return Builder<Transaction> */
    private function query(User $user): Builder
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->countable();
    }

    private function escapeLike(string $value): string
    {
        return str_replace([self::LIKE_ESCAPE, '%', '_'], [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'], $value);
    }
}
