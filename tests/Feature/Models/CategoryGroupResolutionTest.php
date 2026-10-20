<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Category;
use App\Models\CategoryGroup;
use App\Services\Reports\CategoryGroupMap;

dataset('group resolvers', [
    'model' => [fn (Category $category): ?int => $category->fresh()->effectiveGroup()?->id],
    'report map' => [fn (Category $category): ?int => CategoryGroupMap::load()->groupId($category->id)],
]);

test('a category resolves to the nearest group up its tree', function (Closure $resolve) {
    $food = CategoryGroup::factory()->create(['name' => 'Food']);
    $savings = CategoryGroup::factory()->create(['name' => 'Savings']);
    $root = Category::factory()->create(['group_id' => $food->id]);
    $inheriting = Category::factory()->withParent($root)->create();
    $grandchild = Category::factory()->withParent($inheriting)->create();
    $override = Category::factory()->withParent($root)->create(['group_id' => $savings->id]);
    $overrideChild = Category::factory()->withParent($override)->create();

    expect($resolve($root))->toBe($food->id)
        ->and($resolve($grandchild))->toBe($food->id)
        ->and($resolve($override))->toBe($savings->id)
        ->and($resolve($overrideChild))->toBe($savings->id);
})->with('group resolvers');

test('a tree with no group anywhere resolves to none', function (Closure $resolve) {
    $root = Category::factory()->create();
    $child = Category::factory()->withParent($root)->create();

    expect($resolve($child))->toBeNull();
})->with('group resolvers');

test('the report anchor spans a same-group chain and stops at a different-group boundary', function () {
    $food = CategoryGroup::factory()->create(['name' => 'Food']);
    $savings = CategoryGroup::factory()->create(['name' => 'Savings']);
    $top = Category::factory()->create(['group_id' => $savings->id]);
    $anchor = Category::factory()->withParent($top)->create(['group_id' => $food->id]);
    $sameGroup = Category::factory()->withParent($anchor)->create(['group_id' => $food->id]);
    $leaf = Category::factory()->withParent($sameGroup)->create();
    $otherGroup = Category::factory()->withParent($sameGroup)->create(['group_id' => $savings->id]);

    $map = CategoryGroupMap::load();

    expect($map->anchorId($leaf->id))->toBe($anchor->id)
        ->and($map->groupId($leaf->id))->toBe($food->id)
        ->and($map->anchorId($sameGroup->id))->toBe($anchor->id)
        ->and($map->anchorId($otherGroup->id))->toBe($otherGroup->id);
});

test('deleting a group leaves its categories without a group', function () {
    $group = CategoryGroup::factory()->create();
    $root = Category::factory()->create(['group_id' => $group->id]);

    $group->delete();

    expect($root->fresh()->group_id)->toBeNull();
});
