<?php

declare(strict_types=1);

namespace App\Support\Budget;

use App\Models\Budget;
use Illuminate\Support\Collection;

/**
 * The one definition of which budget allocation covers a category.
 *
 * A line's allocation is inclusive: a numeric line holds its own reserve plus whatever its
 * descendants hold. A line with a null limit is Shared and a category without a line is absent;
 * neither holds money of its own, so they are skipped over, and the numeric lines above and
 * below them are related directly. Every question about covering ancestors and held money goes
 * through here so the plan totals, the reserve checks and the ancestor sync cannot disagree.
 */
final readonly class AllocationTree
{
    /** @var array<int, list<int>> */
    private array $children;

    /**
     * @param  array<int, int|null>  $parents  parent category id by category id
     * @param  array<int, int|null>  $limits  allocation by category id, null for Shared; absent without a line
     */
    public function __construct(private array $parents, private array $limits)
    {
        $children = [];

        foreach ($parents as $id => $parentId) {
            if ($parentId !== null) {
                $children[$parentId][] = $id;
            }
        }

        $this->children = $children;
    }

    /**
     * @param  array<int, int|null>  $parents
     * @param  Collection<int, Budget>  $lines  plan lines with a category
     */
    public static function fromLines(array $parents, Collection $lines): self
    {
        $limits = [];

        foreach ($lines as $line) {
            $limits[$line->category_id] = $line->limit_amount;
        }

        return new self($parents, $limits);
    }

    /**
     * Ancestors that have a numeric line, nearest first, skipping Shared and missing ones.
     *
     * @return list<int>
     */
    public function numericAncestors(int $categoryId): array
    {
        $ancestors = [];
        $seen = [$categoryId => true];

        for ($id = $this->parents[$categoryId] ?? null; $id !== null && ! isset($seen[$id]); $id = $this->parents[$id] ?? null) {
            $seen[$id] = true;

            if (($this->limits[$id] ?? null) !== null) {
                $ancestors[] = $id;
            }
        }

        return $ancestors;
    }

    public function isCovered(int $categoryId): bool
    {
        return $this->numericAncestors($categoryId) !== [];
    }

    /**
     * What the nearest numeric lines below the category hold between them.
     */
    public function held(int $categoryId): int
    {
        $held = 0;
        $pending = $this->children[$categoryId] ?? [];
        $seen = [$categoryId => true];

        while ($pending !== []) {
            $id = array_pop($pending);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            if (($this->limits[$id] ?? null) !== null) {
                $held += $this->limits[$id];

                continue;
            }

            foreach ($this->children[$id] ?? [] as $childId) {
                $pending[] = $childId;
            }
        }

        return $held;
    }

    /**
     * The category's own allocation, or for a Shared or missing line what is dedicated below it.
     */
    public function inclusive(int $categoryId): int
    {
        return $this->limits[$categoryId] ?? $this->held($categoryId);
    }

    /**
     * Money allocated across the plan: numeric lines no other numeric line covers.
     */
    public function allocatedTotal(): int
    {
        $total = 0;

        foreach ($this->limits as $id => $limit) {
            if ($limit !== null && ! $this->isCovered($id)) {
                $total += $limit;
            }
        }

        return $total;
    }
}
