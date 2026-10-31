<?php

declare(strict_types=1);

namespace App\Support\Categories;

use App\Livewire\PayeeReview;
use App\Models\Category;

/**
 * Categories that code finds by name. Renaming, re-parenting, merging away or
 * deleting one silently breaks pay-cycle detection, transfer exclusion and
 * detection, the month-end balance rule or the work and personal picker
 * narrowing, so they are matched by full path within the user's own tree and
 * refused. Drop this once those lookups use a stable key instead of a name.
 */
final class ProtectedCategories
{
    /**
     * @var list<string>
     */
    public const array NAMED_PATHS = ['Income', 'Income / Salary', 'Transfer', 'Balance'];

    /**
     * @return list<string>
     */
    public static function paths(): array
    {
        return [...self::NAMED_PATHS, ...PayeeReview::WORK_ROOTS, ...PayeeReview::PERSONAL_ROOTS];
    }

    /**
     * @param  Category  $category  with its parent chain linked in memory
     */
    public static function isProtected(Category $category): bool
    {
        return self::isProtectedPath($category->fullPath());
    }

    public static function isProtectedPath(string $path): bool
    {
        $path = mb_strtolower(mb_trim($path));

        foreach (self::paths() as $protected) {
            if (mb_strtolower($protected) === $path) {
                return true;
            }
        }

        return false;
    }
}
