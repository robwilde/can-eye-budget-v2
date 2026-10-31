<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Category;

/**
 * In-memory view of the category tree used to place a category under its
 * group without lazy loads. The anchor of a category is the nearest category
 * up the tree (itself included) that has a group of its own.
 */
final readonly class CategoryGroupMap
{
    /**
     * @param  array<int, array{name: string, parent_id: int|null, group_id: int|null, icon: string|null}>  $nodes
     */
    private function __construct(private array $nodes) {}

    public static function load(int $userId): self
    {
        $nodes = [];

        foreach (Category::query()->where('user_id', $userId)->get(['id', 'name', 'parent_id', 'group_id', 'icon']) as $category) {
            $nodes[$category->id] = [
                'name' => $category->name,
                'parent_id' => $category->parent_id,
                'group_id' => $category->group_id,
                'icon' => $category->icon,
            ];
        }

        return new self($nodes);
    }

    public function anchorId(int $categoryId): ?int
    {
        $visited = [];
        $cursor = $categoryId;
        $anchor = null;

        while ($cursor !== null && isset($this->nodes[$cursor]) && ! isset($visited[$cursor])) {
            $own = $this->nodes[$cursor]['group_id'];

            if ($own !== null) {
                if ($anchor !== null && $own !== $this->nodes[$anchor]['group_id']) {
                    break;
                }

                $anchor = $cursor;
            }

            $visited[$cursor] = true;
            $cursor = $this->nodes[$cursor]['parent_id'];
        }

        return $anchor;
    }

    public function groupId(int $categoryId): ?int
    {
        $anchor = $this->anchorId($categoryId);

        return $anchor === null ? null : $this->nodes[$anchor]['group_id'];
    }

    /**
     * Every category that resolves to the group through its anchor, so a
     * child inherits its root's group unless it carries a group of its own.
     *
     * @return list<int>
     */
    public function categoryIdsInGroup(int $groupId): array
    {
        $ids = array_values(array_filter(
            array_keys($this->nodes),
            fn (int $id): bool => $this->groupId($id) === $groupId,
        ));

        sort($ids);

        return $ids;
    }

    /**
     * The category and every descendant below it, at any depth.
     *
     * @return list<int>
     */
    public function descendantIdsOf(int $categoryId): array
    {
        if (! isset($this->nodes[$categoryId])) {
            return [];
        }

        $ids = [$categoryId => true];

        do {
            $added = false;

            foreach ($this->nodes as $id => $node) {
                if (! isset($ids[$id]) && $node['parent_id'] !== null && isset($ids[$node['parent_id']])) {
                    $ids[$id] = true;
                    $added = true;
                }
            }
        } while ($added);

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * The direct child of the anchor on the path to the category, so deeper
     * levels roll up into it. Null when the category is the anchor itself.
     */
    public function childUnder(int $anchorId, int $categoryId): ?int
    {
        $visited = [];
        $cursor = $categoryId;

        while ($cursor !== $anchorId && isset($this->nodes[$cursor]) && ! isset($visited[$cursor])) {
            $parent = $this->nodes[$cursor]['parent_id'];

            if ($parent === $anchorId) {
                return $cursor;
            }

            $visited[$cursor] = true;
            $cursor = $parent;

            if ($cursor === null) {
                return null;
            }
        }

        return null;
    }

    public function name(int $categoryId): string
    {
        return $this->nodes[$categoryId]['name'] ?? '';
    }

    public function icon(int $categoryId): ?string
    {
        $visited = [];
        $cursor = $categoryId;

        while ($cursor !== null && isset($this->nodes[$cursor]) && ! isset($visited[$cursor])) {
            if ($this->nodes[$cursor]['icon'] !== null) {
                return $this->nodes[$cursor]['icon'];
            }

            $visited[$cursor] = true;
            $cursor = $this->nodes[$cursor]['parent_id'];
        }

        return null;
    }
}
