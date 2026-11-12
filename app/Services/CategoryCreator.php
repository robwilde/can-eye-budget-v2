<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CategoriesLockedException;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The one place a user-facing create goes through, so the per-user category
 * lock is checked once. Import category mapping, provisioning and the scoping
 * migration create categories without calling it (ADR 0002).
 */
final class CategoryCreator
{
    /**
     * @throws CategoriesLockedException
     * @throws ModelNotFoundException when the parent is not one of the user's categories
     */
    public function create(User $user, string $name, ?int $parentId = null): Category
    {
        $locked = User::query()->whereKey($user->id)->firstOrFail()->preferences->categoriesLocked;

        if ($locked) {
            throw new CategoriesLockedException(__('Categories are locked, so new categories cannot be created.'));
        }

        $parent = $parentId === null ? null : Category::query()->where('user_id', $user->id)->findOrFail($parentId);

        return Category::create([
            'user_id' => $user->id,
            'name' => $name,
            'parent_id' => $parentId,
            'color' => $parent?->color,
        ]);
    }
}
