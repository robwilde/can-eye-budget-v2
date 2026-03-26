<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Category;
use App\Models\Transaction;

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
