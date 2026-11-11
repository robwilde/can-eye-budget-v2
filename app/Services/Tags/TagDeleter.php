<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\Models\Tag;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
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

        DB::transaction(function () use ($userId, $tagId, $replacementId, $ids): void {
            $locked = Tag::query()
                ->where('user_id', $userId)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($locked->count() !== count($ids)) {
                throw new ModelNotFoundException()->setModel(Tag::class, $ids);
            }

            if ($replacementId !== null) {
                $this->moveLinks($userId, $tagId, $replacementId);
            }

            $locked->firstWhere('id', $tagId)?->delete();
        });
    }

    /**
     * @return int the number of tags deleted
     *
     * @throws Throwable
     */
    public function deleteAll(int $userId): int
    {
        return DB::transaction(fn (): int => Tag::query()->where('user_id', $userId)->delete());
    }

    private function moveLinks(int $userId, int $tagId, int $replacementId): void
    {
        $links = DB::table('tag_transaction')
            ->join('transactions', 'transactions.id', '=', 'tag_transaction.transaction_id')
            ->where('tag_transaction.tag_id', $tagId)
            ->where('transactions.user_id', $userId)
            ->select('tag_transaction.transaction_id', DB::raw((string) $replacementId.' as tag_id'));

        DB::table('tag_transaction')->insertOrIgnoreUsing(['transaction_id', 'tag_id'], $links);
    }
}
