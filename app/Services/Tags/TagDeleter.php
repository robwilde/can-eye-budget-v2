<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\Models\Tag;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Throwable;

/**
 * Deletes a user's tags. Deleting one with a replacement first moves every transaction link
 * to the replacement, then removes the tag, in one DB transaction.
 */
final readonly class TagDeleter
{
    /**
     * @throws Throwable
     */
    public function delete(int $userId, int $tagId, ?int $replacementId = null): void
    {
        if ($replacementId === $tagId) {
            throw new InvalidArgumentException('A tag cannot be replaced by itself.');
        }

        $ids = $replacementId === null ? [$tagId] : [$tagId, $replacementId];

        Tag::query()->getConnection()->transaction(function () use ($userId, $tagId, $replacementId, $ids): void {
            $locked = Tag::query()
                ->where('user_id', $userId)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($locked->count() !== count($ids)) {
                throw new ModelNotFoundException()->setModel(Tag::class, $ids);
            }

            $tags = $locked->keyBy('id');

            if ($replacementId !== null) {
                $this->moveLinks($userId, $tags[$tagId], $tags[$replacementId]);
            }

            $tags[$tagId]->delete();
        });
    }

    /**
     * @return int the number of tags deleted
     */
    public function deleteAll(int $userId): int
    {
        return Tag::query()->where('user_id', $userId)->delete();
    }

    private function moveLinks(int $userId, Tag $from, Tag $to): void
    {
        $existing = $to->transactions()->allRelatedIds();

        $from->transactions()
            ->withTrashed()
            ->where('transactions.user_id', $userId)
            ->whereNotIn('transactions.id', $existing)
            ->pluck('transactions.id')
            ->chunk(500)
            ->each(fn ($ids) => $to->transactions()->attach($ids->values()->all()));
    }
}
