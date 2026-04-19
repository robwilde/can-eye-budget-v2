<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

test('factory creates a valid category', function () {
    $category = Category::factory()->create();

    expect($category)
        ->toBeInstanceOf(Category::class)
        ->and($category->exists)->toBeTrue();
});

test('parent_id is nullable', function () {
    $category = Category::factory()->create();

    expect($category->parent_id)->toBeNull();
});

test('category belongs to a parent', function () {
    $parent = Category::factory()->create();
    $child = Category::factory()->withParent($parent)->create();

    expect($child->parent)
        ->toBeInstanceOf(Category::class)
        ->and($child->parent->id)->toBe($parent->id);
});

test('category has many children', function () {
    $parent = Category::factory()->create();
    Category::factory()->count(3)->withParent($parent)->create();

    expect($parent->children)->toHaveCount(3)
        ->each(fn (Pest\Expectation $child) => $child->toBeInstanceOf(Category::class));
});

test('deleting parent nullifies children parent_id', function () {
    $parent = Category::factory()->create();
    $child = Category::factory()->withParent($parent)->create();

    $parent->delete();

    expect($child->fresh()->parent_id)->toBeNull();
});

test('category has many transactions', function () {
    $category = Category::factory()->create();
    Transaction::factory()->count(3)->create(['category_id' => $category->id]);

    expect($category->transactions)
        ->toHaveCount(3)
        ->each(fn (Pest\Expectation $transaction) => $transaction->toBeInstanceOf(Transaction::class));
});

test('anzsic fields are nullable', function () {
    $category = Category::factory()->create();

    expect($category->anzsic_division)
        ->toBeNull()
        ->and($category->anzsic_subdivision)->toBeNull()
        ->and($category->anzsic_group)->toBeNull()
        ->and($category->anzsic_class)->toBeNull();
});

test('icon and color are nullable', function () {
    $category = Category::factory()->create();

    expect($category->icon)
        ->toBeNull()
        ->and($category->color)->toBeNull();
});

test('division state sets anzsic_division', function () {
    $category = Category::factory()->division()->create();

    expect($category->anzsic_division)
        ->toBe('G')
        ->and($category->anzsic_subdivision)->toBeNull()
        ->and($category->anzsic_group)->toBeNull()
        ->and($category->anzsic_class)->toBeNull();
});

test('subdivision state sets division and subdivision', function () {
    $category = Category::factory()->subdivision()->create();

    expect($category->anzsic_division)
        ->toBe('G')
        ->and($category->anzsic_subdivision)->toBe('41');
});

test('group state sets division, subdivision, and group', function () {
    $category = Category::factory()->group()->create();

    expect($category->anzsic_division)
        ->toBe('G')
        ->and($category->anzsic_subdivision)->toBe('41')
        ->and($category->anzsic_group)->toBe('411');
});

test('anzsicClass state sets all four anzsic levels', function () {
    $category = Category::factory()->anzsicClass()->create();

    expect($category->anzsic_division)
        ->toBe('G')
        ->and($category->anzsic_subdivision)->toBe('41')
        ->and($category->anzsic_group)->toBe('411')
        ->and($category->anzsic_class)->toBe('4110');
});

test('withIcon state sets icon', function () {
    $category = Category::factory()->withIcon('shopping-cart')->create();

    expect($category->icon)->toBe('shopping-cart');
});

test('withColor state sets color', function () {
    $category = Category::factory()->withColor('#DC2626')->create();

    expect($category->color)->toBe('#DC2626');
});

test('is_hidden defaults to false', function () {
    $category = Category::factory()->create();

    expect($category->is_hidden)->toBeFalse();
});

test('hidden factory state sets is_hidden to true', function () {
    $category = Category::factory()->hidden()->create();

    expect($category->is_hidden)->toBeTrue();
});

test('scopeVisible excludes hidden categories', function () {
    Category::factory()->create(['name' => 'Visible']);
    Category::factory()->hidden()->create(['name' => 'Hidden']);

    $visible = Category::visible()->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->name)->toBe('Visible');
});

test('fullPath returns name for top-level category', function () {
    $category = Category::factory()->create(['name' => 'Office']);

    expect($category->fullPath())->toBe('Office');
});

test('fullPath returns parent / child for nested category', function () {
    $parent = Category::factory()->create(['name' => 'Office']);
    $child = Category::factory()->withParent($parent)->create(['name' => 'Software']);

    expect($child->fullPath())->toBe('Office / Software');
});

test('fullPath returns three levels for deeply nested category', function () {
    $grandparent = Category::factory()->create(['name' => 'Office']);
    $parent = Category::factory()->withParent($grandparent)->create(['name' => 'Training']);
    $child = Category::factory()->withParent($parent)->create(['name' => 'Subscription']);

    $child->load('parent.parent');

    expect($child->fullPath())->toBe('Office / Training / Subscription');
});

test('visibleSortedByFullPath sorts by full path not leaf name', function () {
    $bills = Category::factory()->create(['name' => 'Bills']);
    Category::factory()->withParent($bills)->create(['name' => 'Zebra']);

    $entertainment = Category::factory()->create(['name' => 'Entertainment']);
    Category::factory()->withParent($entertainment)->create(['name' => 'Alpha']);

    $result = Category::visibleSortedByFullPath();
    $paths = $result->map(fn (Category $c) => $c->fullPath())->all();

    expect($paths)->toContain('Bills / Zebra', 'Entertainment / Alpha')
        ->and(array_search('Bills / Zebra', $paths, true))
        ->toBeLessThan(array_search('Entertainment / Alpha', $paths, true));
});

test('visibleSortedByFullPath excludes hidden categories', function () {
    Category::factory()->create(['name' => 'Visible']);
    Category::factory()->hidden()->create(['name' => 'Hidden']);

    $result = Category::visibleSortedByFullPath();
    $names = $result->pluck('name')->all();

    expect($names)->toContain('Visible')
        ->and($names)->not->toContain('Hidden');
});

test('visibleSortedByFullPath returns values-reset collection', function () {
    Category::factory()->count(3)->create();

    $result = Category::visibleSortedByFullPath();

    expect($result->keys()->all())->toBe(range(0, $result->count() - 1));
});

test('resolveIcon returns own icon when set', function () {
    $category = Category::factory()->withIcon('shopping-cart')->create();

    expect($category->resolveIcon())->toBe('shopping-cart');
});

test('resolveIcon falls back to parent icon when own icon is null', function () {
    $parent = Category::factory()->withIcon('shopping-cart')->create();
    $child = Category::factory()->withParent($parent)->create();

    expect($child->resolveIcon())->toBe('shopping-cart');
});

test('resolveIcon walks ancestors until an icon is found', function () {
    $grandparent = Category::factory()->withIcon('coffee')->create();
    $parent = Category::factory()->withParent($grandparent)->create();
    $child = Category::factory()->withParent($parent)->create();

    expect($child->resolveIcon())->toBe('coffee');
});

test('resolveIcon returns null when neither category nor ancestors set an icon', function () {
    $parent = Category::factory()->create();
    $child = Category::factory()->withParent($parent)->create();

    expect($child->resolveIcon())->toBeNull();
});

test('resolveIcon fires no queries when parent.parent is eager-loaded on a grandchild', function () {
    $grandparent = Category::factory()->withIcon('bolt')->create();
    $parent = Category::factory()->withParent($grandparent)->create();
    Category::factory()->withParent($parent)->create();

    $grandchild = Category::query()
        ->with('parent.parent')
        ->where('parent_id', $parent->id)
        ->firstOrFail();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $icon = $grandchild->resolveIcon();

    expect($icon)->toBe('bolt')
        ->and(DB::getQueryLog())->toBeEmpty();
});
