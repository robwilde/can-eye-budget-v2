<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Livewire\CategoryEditor;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('component renders for authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->assertSuccessful();
});

test('displays categories with transaction counts', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['name' => 'Groceries']);
    Transaction::factory()->for($user)->count(5)->create(['category_id' => $category->id]);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->assertSee('Groceries')
        ->assertSee('5');
});

test('displays full path for nested categories', function () {
    $user = User::factory()->create();
    $parent = Category::factory()->create(['name' => 'Office']);
    Category::factory()->withParent($parent)->create(['name' => 'Software']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->assertSee('Office / Software');
});

test('search filters categories by full path', function () {
    $user = User::factory()->create();
    Category::factory()->create(['name' => 'Groceries']);
    Category::factory()->create(['name' => 'Entertainment']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->set('search', 'Grocer')
        ->assertSee('Groceries')
        ->assertDontSee('Entertainment');
});

test('search is case insensitive', function () {
    $user = User::factory()->create();
    Category::factory()->create(['name' => 'Groceries']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->set('search', 'groceries')
        ->assertSee('Groceries');
});

test('can select a category and see its transactions', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['name' => 'Bills']);
    Transaction::factory()->for($user)->create([
        'category_id' => $category->id,
        'description' => 'Aussie Broadband',
    ]);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $category->id)
        ->assertSet('selectedCategoryId', $category->id)
        ->assertSee('Aussie Broadband');
});

test('can rename a category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['name' => 'Old Name']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $category->id)
        ->set('editingName', 'New Name')
        ->call('saveRename');

    expect($category->fresh()->name)->toBe('New Name');
});

test('rename validates required name', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $category->id)
        ->set('editingName', '')
        ->call('saveRename')
        ->assertHasErrors(['editingName']);
});

test('can hide a category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['is_hidden' => false]);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('toggleHidden', $category->id);

    expect($category->fresh()->is_hidden)->toBeTrue();
});

test('can unhide a category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->hidden()->create();

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->set('showHidden', true)
        ->call('toggleHidden', $category->id);

    expect($category->fresh()->is_hidden)->toBeFalse();
});

test('hidden categories excluded by default', function () {
    $user = User::factory()->create();
    Category::factory()->create(['name' => 'Visible Category']);
    Category::factory()->hidden()->create(['name' => 'Secret Stash']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->assertSee('Visible Category')
        ->assertDontSee('Secret Stash');
});

test('hidden categories shown when toggled', function () {
    $user = User::factory()->create();
    Category::factory()->hidden()->create(['name' => 'Hidden Category']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->set('showHidden', true)
        ->assertSee('Hidden Category');
});

test('can create a new top-level category', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('openCreateForm')
        ->set('newCategoryName', 'New Category')
        ->call('createCategory')
        ->assertSet('showCreateForm', false);

    expect(Category::query()->where('name', 'New Category')->first())
        ->not->toBeNull()
        ->parent_id->toBeNull();
});

test('can create a subcategory with parent', function () {
    $user = User::factory()->create();
    $parent = Category::factory()->create(['name' => 'Office']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('openCreateForm', $parent->id)
        ->assertSet('newParentId', $parent->id)
        ->set('newCategoryName', 'Printer')
        ->call('createCategory');

    $child = Category::query()->where('name', 'Printer')->first();
    expect($child)
        ->not->toBeNull()
        ->parent_id->toBe($parent->id);
});

test('create validates required name', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('openCreateForm')
        ->set('newCategoryName', '')
        ->call('createCategory')
        ->assertHasErrors(['newCategoryName']);
});

test('can delete a category without transactions', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['name' => 'Empty']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('confirmDelete', $category->id)
        ->assertSet('showDeleteConfirm', true)
        ->assertSet('deletingTransactionCount', 0)
        ->call('deleteCategory');

    expect(Category::query()->find($category->id))->toBeNull();
});

test('delete category nullifies transaction category_id', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    $transaction = Transaction::factory()->for($user)->create(['category_id' => $category->id]);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('confirmDelete', $category->id)
        ->assertSet('deletingTransactionCount', 1)
        ->call('deleteCategory');

    expect($transaction->fresh()->category_id)->toBeNull();
});

test('deleting selected category clears selection', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $category->id)
        ->assertSet('selectedCategoryId', $category->id)
        ->call('confirmDelete', $category->id)
        ->call('deleteCategory')
        ->assertSet('selectedCategoryId', null);
});

test('transaction counts and list are scoped to authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $category = Category::factory()->create(['name' => 'Groceries']);

    Transaction::factory()->for($user)->count(3)->create(['category_id' => $category->id]);
    Transaction::factory()->for($otherUser)->count(5)->create([
        'category_id' => $category->id,
        'description' => 'Other User Transaction',
    ]);

    $component = Livewire::actingAs($user)
        ->test(CategoryEditor::class);

    $categories = $component->viewData('categories');
    $groceries = $categories->firstWhere('name', 'Groceries');
    expect($groceries['transactions_count'])->toBe(3);

    $component->call('selectCategory', $category->id)
        ->assertDontSee('Other User Transaction');
});

test('categories sorted by transaction count descending', function () {
    $user = User::factory()->create();
    $few = Category::factory()->create(['name' => 'Few']);
    $many = Category::factory()->create(['name' => 'Many']);
    Transaction::factory()->for($user)->count(2)->create(['category_id' => $few->id]);
    Transaction::factory()->for($user)->count(10)->create(['category_id' => $many->id]);

    $component = Livewire::actingAs($user)
        ->test(CategoryEditor::class);

    $categories = $component->viewData('categories');
    expect($categories->first()['name'])->toBe('Many')
        ->and($categories->last()['name'])->toBe('Few');
});
