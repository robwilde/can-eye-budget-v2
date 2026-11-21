<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\Models\Tag;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sets the tags of a transaction. A linked transfer is one item, so a change reaches both of
 * its legs. A change made by hand adds `tags` to the locked fields of every row it touches, so
 * bank sync never overwrites it.
 */
final readonly class TransactionTagger
{
    public const string LOCKED_FIELD = 'tags';

    /**
     * The tag ids the user owns out of those requested: an empty list stays empty, which clears
     * tags, and a list naming only tags the user does not own is null, which changes nothing.
     *
     * @param  list<int|string>  $requested
     * @return list<int>|null
     */
    public function resolve(int $userId, array $requested): ?array
    {
        if ($requested === []) {
            return [];
        }

        $owned = Tag::query()
            ->where('user_id', $userId)
            ->whereIn('id', array_map(intval(...), $requested))
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $owned === [] ? null : $owned;
    }

    /**
     * Makes $requested the tags of the transaction and, for a transfer, of its other leg.
     * Tag ids the user does not own are dropped; a request naming only such ids leaves the tags
     * alone.
     *
     * @param  list<int|string>  $requested
     * @return bool whether any tag link changed
     *
     * @throws Throwable
     */
    public function replace(Transaction $transaction, array $requested): bool
    {
        $tagIds = $this->resolve($transaction->user_id, $requested);

        return $tagIds !== null && $this->apply($transaction, $tagIds);
    }

    /**
     * Makes $tagIds, already resolved to tags the user owns, the tags of the transaction and, for
     * a transfer, of its other leg.
     *
     * @param  list<int>  $tagIds
     * @return bool whether any tag link changed
     *
     * @throws Throwable
     */
    public function apply(Transaction $transaction, array $tagIds): bool
    {
        return DB::transaction(function () use ($transaction, $tagIds): bool {
            $changed = false;

            foreach ($this->lockedLegs($transaction) as $leg) {
                $result = $leg->tags()->sync($tagIds);

                if ($result['attached'] !== [] || $result['detached'] !== []) {
                    $this->lock($leg);
                    $changed = true;
                }
            }

            return $changed;
        });
    }

    /**
     * The requested tags the user owns and the transaction does not yet carry. Reads only.
     *
     * @param  list<int|string>  $requested
     * @return list<int>
     */
    public function missing(Transaction $transaction, array $requested): array
    {
        $owned = $this->resolve($transaction->user_id, $requested) ?? [];

        if ($owned === []) {
            return [];
        }

        $present = $transaction->tags()->whereIn('tags.id', $owned)->pluck('tags.id')->map(static fn (mixed $id): int => (int) $id)->all();

        return array_values(array_diff($owned, $present));
    }

    /**
     * Adds the tags the user owns to the transaction and, for a transfer, its other leg, keeping
     * every tag already there. A rule adds tags, so unlike a change made by hand it does not lock
     * the field. Tag ids that are gone or not the user's are skipped.
     *
     * @param  list<int|string>  $requested
     * @return bool whether any tag link was added
     *
     * @throws Throwable
     */
    public function add(Transaction $transaction, array $requested): bool
    {
        $tagIds = $this->resolve($transaction->user_id, $requested) ?? [];

        if ($tagIds === []) {
            return false;
        }

        return DB::transaction(function () use ($transaction, $tagIds): bool {
            $changed = false;

            foreach ($this->lockedLegs($transaction) as $leg) {
                $changed = $leg->tags()->syncWithoutDetaching($tagIds)['attached'] !== [] || $changed;
            }

            return $changed;
        });
    }

    /**
     * Adds the tag when the transaction does not carry it and removes it when it does.
     *
     * @throws Throwable
     */
    public function toggle(Transaction $transaction, int $tagId): bool
    {
        if ($this->resolve($transaction->user_id, [$tagId]) === null) {
            return false;
        }

        return DB::transaction(function () use ($transaction, $tagId): bool {
            $legs = $this->lockedLegs($transaction);
            $primary = $legs->firstWhere('id', $transaction->id);

            if ($primary === null) {
                return false;
            }

            $present = $primary->tags()->whereKey($tagId)->exists();

            foreach ($legs as $leg) {
                $present ? $leg->tags()->detach($tagId) : $leg->tags()->syncWithoutDetaching([$tagId]);
                $this->lock($leg);
            }

            return true;
        });
    }

    /**
     * The transaction and its transfer partner, both of the same user, locked in id order.
     *
     * @return Collection<int, Transaction>
     */
    private function lockedLegs(Transaction $transaction): Collection
    {
        $ids = $transaction->transfer_pair_id === null
            ? [$transaction->id]
            : [$transaction->id, $transaction->transfer_pair_id];

        return Transaction::query()
            ->where('user_id', $transaction->user_id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function lock(Transaction $transaction): void
    {
        $locked = $transaction->locked_fields ?? [];

        if (in_array(self::LOCKED_FIELD, $locked, true)) {
            return;
        }

        $transaction->forceFill(['locked_fields' => [...$locked, self::LOCKED_FIELD]])->save();
    }
}
