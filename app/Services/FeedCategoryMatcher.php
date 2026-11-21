<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;

/**
 * Maps a category name supplied by a bank feed or a CSV file onto one of the user's
 * existing categories. It never creates a category.
 *
 * Resolution order, within the user's own tree, the way CategorySeedRules resolves paths:
 * the exact full path, then the path with plural/singular, spacing and punctuation
 * variants ignored, then the top-level category named by the first segment of a
 * "Parent:Child" value. Hidden categories are never targets.
 */
final class FeedCategoryMatcher
{
    /** @var array<int, array{exact: array<string, int>, alias: array<string, int>}> */
    private array $indexes = [];

    public function match(int $userId, ?string $value): ?int
    {
        $segments = $this->segments($value);

        if ($segments === []) {
            return null;
        }

        $index = $this->index($userId);

        $exact = $index['exact'][mb_strtolower(implode(' / ', $segments))] ?? null;

        if ($exact !== null) {
            return $exact;
        }

        $alias = $index['alias'][$this->aliasKey($segments)] ?? null;

        if ($alias !== null) {
            return $alias;
        }

        if (count($segments) > 1) {
            return $index['alias'][$this->aliasKey([$segments[0]])] ?? null;
        }

        return null;
    }

    /** @return list<string> */
    private function segments(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $parts = preg_split('/\s*[:\/>]\s*/u', $value) ?: [];
        $segments = [];

        foreach ($parts as $part) {
            $clean = mb_trim((string) preg_replace('/[\s\h]+/u', ' ', $part));

            if ($clean !== '') {
                $segments[] = $clean;
            }
        }

        return $segments;
    }

    /** @param  list<string>  $segments */
    private function aliasKey(array $segments): string
    {
        return implode('/', array_map($this->canonical(...), $segments));
    }

    private function canonical(string $segment): string
    {
        $name = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', str_replace('&', 'and', mb_strtolower($segment)));

        if (str_ends_with($name, 'ies') && mb_strlen($name) > 4) {
            return mb_substr($name, 0, -3).'y';
        }

        if (str_ends_with($name, 's') && ! str_ends_with($name, 'ss') && mb_strlen($name) > 3) {
            return mb_substr($name, 0, -1);
        }

        return $name;
    }

    /** @return array{exact: array<string, int>, alias: array<string, int>} */
    private function index(int $userId): array
    {
        if (isset($this->indexes[$userId])) {
            return $this->indexes[$userId];
        }

        $exact = [];
        $alias = [];

        foreach (Category::allWithLinkedParents($userId)->sortBy('id') as $category) {
            if ($category->is_hidden) {
                continue;
            }

            $path = $category->fullPath();
            $exact[mb_strtolower($path)] ??= $category->id;
            $alias[$this->aliasKey(explode(' / ', $path))] ??= $category->id;
        }

        return $this->indexes[$userId] = ['exact' => $exact, 'alias' => $alias];
    }
}
