<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\UserPreferences;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The user's "recently used" category shortcut (ADR 0002): up to four ids, most
 * recent first, written only when the user saves a category they picked by
 * hand. Rules, imports and feed sync never call record().
 */
final class RecentCategoryPicks
{
    public function record(User $user, int $categoryId): void
    {
        if (! Category::query()->where('user_id', $user->id)->whereKey($categoryId)->exists()) {
            return;
        }

        DB::transaction(function () use ($user, $categoryId): void {
            $current = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail()->preferences->recentCategoryIds;
            $picks = array_slice([$categoryId, ...array_values(array_diff($current, [$categoryId]))], 0, UserPreferences::RECENT_CATEGORY_LIMIT);

            if ($picks !== $current) {
                $user->updatePreferences(['recentCategoryIds' => $picks]);
            }
        });
    }

    /**
     * @return Collection<int, Category> The user's own visible categories among the picks, parents linked, most recent first.
     */
    public function categoriesFor(User $user): Collection
    {
        $categories = Category::allWithLinkedParents($user->id)->keyBy('id');

        return collect($user->preferences->recentCategoryIds)
            ->map(fn (int $id): ?Category => $categories->get($id))
            ->filter(fn (?Category $category): bool => $category !== null && ! $category->is_hidden)
            ->values();
    }
}
