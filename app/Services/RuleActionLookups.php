<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\PlannedTransaction;

/**
 * Answers the existence checks a rule's actions make, once per key.
 *
 * Built fresh for one dry run and then dropped, so a later write can never be
 * hidden behind a stale answer.
 */
final class RuleActionLookups
{
    /** @var array<int, bool> */
    private array $visibleCategories = [];

    /** @var array<string, bool> */
    private array $ownedPlans = [];

    public function categoryIsVisible(int $categoryId): bool
    {
        return $this->visibleCategories[$categoryId] ??= Category::visible()->where('id', $categoryId)->exists();
    }

    public function planIsOwnedBy(int $plannedId, int $userId): bool
    {
        return $this->ownedPlans["{$plannedId}:{$userId}"] ??= PlannedTransaction::where('id', $plannedId)->where('user_id', $userId)->exists();
    }
}
