<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\User;
use App\Services\Reports\CategoryGroupMap;

dataset('group resolvers', [
    'model' => [fn (Category $category): ?int => $category->fresh()->effectiveGroup()?->id],
    'report map' => [fn (Category $category): ?int => CategoryGroupMap::load($category->user_id)->groupId($category->id)],
]);

test('a category resolves to the nearest group up its tree', function (Closure $resolve) {
    $user = User::factory()->create();
    $food = CategoryGroup::factory()->for($user)->create(['name' => 'Food']);
    $savings = CategoryGroup::factory()->for($user)->create(['name' => 'Savings']);
    $root = Category::factory()->for($user)->create(['group_id' => $food->id]);
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
    $root = Category::factory()->for(User::factory()->create())->create();
    $child = Category::factory()->withParent($root)->create();

    expect($resolve($child))->toBeNull();
})->with('group resolvers');

test('the report anchor spans a same-group chain and stops at a different-group boundary', function () {
    $user = User::factory()->create();
    $food = CategoryGroup::factory()->for($user)->create(['name' => 'Food']);
    $savings = CategoryGroup::factory()->for($user)->create(['name' => 'Savings']);
    $top = Category::factory()->for($user)->create(['group_id' => $savings->id]);
    $anchor = Category::factory()->withParent($top)->create(['group_id' => $food->id]);
    $sameGroup = Category::factory()->withParent($anchor)->create(['group_id' => $food->id]);
    $leaf = Category::factory()->withParent($sameGroup)->create();
    $otherGroup = Category::factory()->withParent($sameGroup)->create(['group_id' => $savings->id]);

    $map = CategoryGroupMap::load($user->id);

    expect($map->anchorId($leaf->id))->toBe($anchor->id)
        ->and($map->groupId($leaf->id))->toBe($food->id)
        ->and($map->anchorId($sameGroup->id))->toBe($anchor->id)
        ->and($map->anchorId($otherGroup->id))->toBe($otherGroup->id);
});

test('deleting a group leaves its categories without a group', function () {
    $user = User::factory()->create();
    $group = CategoryGroup::factory()->for($user)->create();
    $root = Category::factory()->for($user)->create(['group_id' => $group->id]);

    $group->delete();

    expect($root->fresh()->group_id)->toBeNull();
});

test('descendantIdsOf returns the category and every descendant at any depth', function () {
    $user = User::factory()->create();
    $root = Category::factory()->for($user)->create();
    $child = Category::factory()->withParent($root)->create();
    $grandchild = Category::factory()->withParent($child)->create();
    $sibling = Category::factory()->withParent($root)->create();
    $unrelated = Category::factory()->for($user)->create();

    $map = CategoryGroupMap::load($user->id);

    expect($map->descendantIdsOf($root->id))->toBe(collect([$root->id, $child->id, $grandchild->id, $sibling->id])->sort()->values()->all())
        ->and($map->descendantIdsOf($child->id))->toBe([$child->id, $grandchild->id])
        ->and($map->descendantIdsOf($unrelated->id))->toBe([$unrelated->id])
        ->and($map->descendantIdsOf(999999))->toBe([]);
});

test('the group map only holds the given user\'s categories and groups', function () {
    [$user, $other] = User::factory()->count(2)->create()->all();
    $food = CategoryGroup::factory()->for($user)->create(['name' => 'Food']);
    $mine = Category::factory()->for($user)->create(['group_id' => $food->id]);
    $theirGroup = CategoryGroup::factory()->for($other)->create(['name' => 'Food']);
    $theirs = Category::factory()->for($other)->create(['group_id' => $theirGroup->id]);
    $theirChild = Category::factory()->withParent($theirs)->create();

    $map = CategoryGroupMap::load($user->id);

    expect($map->descendantIdsOf($mine->id))->toBe([$mine->id])
        ->and($map->descendantIdsOf($theirs->id))->toBe([])
        ->and($map->groupId($theirChild->id))->toBeNull()
        ->and($map->categoryIdsInGroup($theirGroup->id))->toBe([])
        ->and($map->categoryIdsInGroup($food->id))->toBe([$mine->id]);
});
