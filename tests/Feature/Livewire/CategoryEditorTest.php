<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Livewire\CategoryEditor;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Transaction;
use App\Models\User;
use App\Services\UserCategoryProvisioner;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

test('component renders for authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->assertSuccessful();
});

test('displays categories with transaction counts', function () {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create(['name' => 'Groceries']);
    Transaction::factory()->for($user)->count(5)->create(['category_id' => $category->id]);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->assertSee('Groceries')
        ->assertSee('5');
});

test('displays full path for nested categories when searching', function () {
    $user = User::factory()->create();
    $parent = Category::factory()->for($user)->create(['name' => 'Office']);
    Category::factory()->withParent($parent)->create(['name' => 'Software']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->set('search', 'Software')
        ->assertSee('Office / Software');
});

test('browse mode shows own segment grouped under parent', function () {
    $user = User::factory()->create();
    $parent = Category::factory()->for($user)->create(['name' => 'Office']);
    Category::factory()->withParent($parent)->create(['name' => 'Software']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->assertSee('Office')
        ->assertSee('Software')
        ->assertDontSee('Office / Software');
});

test('selecting an already-expanded category collapses it', function () {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create(['name' => 'Groceries']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $category->id)
        ->assertSet('selectedCategoryId', $category->id)
        ->call('selectCategory', $category->id)
        ->assertSet('selectedCategoryId', null);
});

test('categories render inline without the modal', function () {
    $user = User::factory()->create();
    Category::factory()->for($user)->create(['name' => 'Groceries']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->assertSeeHtml('cib-card')
        ->assertSee('Groceries')
        ->assertDontSeeHtml('max-h-[80vh]');
});

test('search filters categories by full path', function () {
    $user = User::factory()->create();
    Category::factory()->for($user)->create(['name' => 'Groceries']);
    Category::factory()->for($user)->create(['name' => 'Entertainment']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->set('search', 'Grocer')
        ->assertSee('Groceries')
        ->assertDontSee('Entertainment');
});

test('search is case insensitive', function () {
    $user = User::factory()->create();
    Category::factory()->for($user)->create(['name' => 'Groceries']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->set('search', 'groceries')
        ->assertSee('Groceries');
});

test('can select a category and see its transactions', function () {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create(['name' => 'Bills']);
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
    $category = Category::factory()->for($user)->create(['name' => 'Old Name']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $category->id)
        ->set('editingName', 'New Name')
        ->call('saveRename');

    expect($category->fresh()->name)->toBe('New Name');
});

test('rename validates required name', function () {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $category->id)
        ->set('editingName', '')
        ->call('saveRename')
        ->assertHasErrors(['editingName']);
});

test('can hide a category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create(['is_hidden' => false]);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('toggleHidden', $category->id);

    expect($category->fresh()->is_hidden)->toBeTrue();
});

test('can unhide a category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->hidden()->create();

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->set('showHidden', true)
        ->call('toggleHidden', $category->id);

    expect($category->fresh()->is_hidden)->toBeFalse();
});

test('hidden categories excluded by default', function () {
    $user = User::factory()->create();
    Category::factory()->for($user)->create(['name' => 'Visible Category']);
    Category::factory()->for($user)->hidden()->create(['name' => 'Secret Stash']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->assertSee('Visible Category')
        ->assertDontSee('Secret Stash');
});

test('hidden categories shown when toggled', function () {
    $user = User::factory()->create();
    Category::factory()->for($user)->hidden()->create(['name' => 'Hidden Category']);

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
    $parent = Category::factory()->for($user)->create(['name' => 'Office']);

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
    $category = Category::factory()->for($user)->create(['name' => 'Empty']);

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
    $category = Category::factory()->for($user)->create();
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
    $category = Category::factory()->for($user)->create();

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
    $category = Category::factory()->for($user)->create(['name' => 'Groceries']);

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

test('categories are ordered alphabetically grouped by full path', function () {
    $user = User::factory()->create();
    Category::factory()->for($user)->create(['name' => 'Zoo']);
    $apple = Category::factory()->for($user)->create(['name' => 'Apple']);
    Category::factory()->withParent($apple)->create(['name' => 'Banana']);
    Category::factory()->withParent($apple)->create(['name' => 'Ant']);

    $component = Livewire::actingAs($user)->test(CategoryEditor::class);
    $paths = collect($component->viewData('categories'))->pluck('full_path')->all();

    expect($paths)->toBe(['Apple', 'Apple / Ant', 'Apple / Banana', 'Zoo']);
});

test('view data carries depth for each category', function () {
    $user = User::factory()->create();
    $office = Category::factory()->for($user)->create(['name' => 'Office']);
    $training = Category::factory()->withParent($office)->create(['name' => 'Training']);
    Category::factory()->withParent($training)->create(['name' => 'Course']);

    $component = Livewire::actingAs($user)->test(CategoryEditor::class);
    $byName = collect($component->viewData('categories'))->keyBy('name');

    expect($byName['Office']['depth'])->toBe(0)
        ->and($byName['Training']['depth'])->toBe(1)
        ->and($byName['Course']['depth'])->toBe(2);
});

test('isSearching view flag reflects the search term', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(CategoryEditor::class)
        ->assertViewHas('isSearching', false)
        ->set('search', 'Off')
        ->assertViewHas('isSearching', true);
});

/* ------------------------------------------------------------------ */
/*  CIB visual contract (ticket #215) */
/* ------------------------------------------------------------------ */

test('cib: category list rows use cat-list-row markup', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $groceries = Category::factory()->for($user)->create(['name' => 'Groceries']);
    $bills = Category::factory()->for($user)->create(['name' => 'Bills']);
    Transaction::factory()->for($user)->for($account)->count(3)->create(['category_id' => $groceries->id]);
    Transaction::factory()->for($user)->for($account)->count(5)->create(['category_id' => $bills->id]);

    $component = Livewire::actingAs($user)->test(CategoryEditor::class);
    $html = $component->html();

    expect(mb_substr_count($html, 'class="cat-list-row'))->toBeGreaterThanOrEqual(2);

    $component
        ->assertSee('Groceries')
        ->assertSee('Bills')
        ->assertSeeHtml('<span class="cat-count">3</span>')
        ->assertSeeHtml('<span class="cat-count">5</span>');

    expect($html)->not->toContain('rounded-lg border border-neutral-200 dark:border-neutral-700');
});

test('cib: rename field label renders as cib-label span', function () {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create(['name' => 'Utilities']);

    $component = Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $category->id);

    $component->assertSeeHtml('class="cib-label');
});

test('cib: nested rows indent by depth and top-level rows are group headers', function () {
    $user = User::factory()->create();
    $office = Category::factory()->for($user)->create(['name' => 'Office']);
    Category::factory()->withParent($office)->create(['name' => 'Software']);

    $html = Livewire::actingAs($user)->test(CategoryEditor::class)->html();

    expect($html)->toContain('cat-group-head')
        ->and($html)->toContain('padding-left: 0.875rem')
        ->and($html)->toContain('padding-left: 2.125rem');
});

/* ------------------------------------------------------------------ */
/*  Nested category parents (issue #353) */
/* ------------------------------------------------------------------ */

test('parent dropdown lists nested categories by full path', function () {
    $user = User::factory()->create();
    $office = Category::factory()->for($user)->create(['name' => 'Office']);
    Category::factory()->withParent($office)->create(['name' => 'Online Service']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('openCreateForm')
        ->assertSee('Office / Online Service');
});

test('search matches an ancestor three levels up', function () {
    $user = User::factory()->create();
    $a = Category::factory()->for($user)->create(['name' => 'Alpha']);
    $b = Category::factory()->withParent($a)->create(['name' => 'Bravo']);
    $c = Category::factory()->withParent($b)->create(['name' => 'Charlie']);
    Category::factory()->withParent($c)->create(['name' => 'Delta']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->set('search', 'Alpha')
        ->assertSee('Alpha / Bravo / Charlie / Delta');
});

test('re-parent rejects moving a category under its own descendant', function () {
    $user = User::factory()->create();
    $a = Category::factory()->for($user)->create(['name' => 'Alpha']);
    $b = Category::factory()->withParent($a)->create(['name' => 'Bravo']);
    $c = Category::factory()->withParent($b)->create(['name' => 'Charlie']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $a->id)
        ->set('editingParentId', $c->id)
        ->call('saveRename')
        ->assertHasErrors('editingParentId');

    expect($a->fresh()->parent_id)->toBeNull();
});

test('re-parent rejects moving a category under itself', function () {
    $user = User::factory()->create();
    $office = Category::factory()->for($user)->create(['name' => 'Office']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $office->id)
        ->set('editingParentId', $office->id)
        ->call('saveRename')
        ->assertHasErrors('editingParentId');

    expect($office->fresh()->parent_id)->toBeNull();
});

it('keeps category edits within one user', function () {
    [$ann, $ben] = User::factory()->count(2)->create()->all();
    app(UserCategoryProvisioner::class)->provision($ann);
    app(UserCategoryProvisioner::class)->provision($ben);
    $named = fn (User $user, string $name): Category => Category::query()->where('user_id', $user->id)->whereNull('parent_id')->where('name', $name)->sole();

    $annGroceries = $named($ann, 'Groceries');
    $annPets = $named($ann, 'Pets');
    $benPets = $named($ben, 'Pets');
    $benHealth = $named($ben, 'Health');

    Livewire::actingAs($ann)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $annGroceries->id)
        ->set('editingName', 'Food shopping')
        ->call('saveRename')
        ->call('toggleHidden', $annPets->id)
        ->call('openGroupForm', $annGroceries->group_id)
        ->set('groupName', 'Eating in')
        ->call('saveGroup')
        ->call('toggleHidden', $benPets->id)
        ->call('selectCategory', $benHealth->id)
        ->assertSet('selectedCategoryId', $annGroceries->id);

    expect($named($ann, 'Food shopping')->exists)->toBeTrue()
        ->and($named($ann, 'Pets')->is_hidden)->toBeTrue()
        ->and(CategoryGroup::query()->where('user_id', $ann->id)->where('name', 'Eating in')->exists())->toBeTrue()
        ->and($named($ben, 'Groceries')->exists)->toBeTrue()
        ->and($named($ben, 'Pets')->is_hidden)->toBeFalse()
        ->and(CategoryGroup::query()->where('user_id', $ben->id)->where('name', 'Food')->exists())->toBeTrue()
        ->and(CategoryGroup::query()->where('user_id', $ben->id)->where('name', 'Eating in')->exists())->toBeFalse();
});

it('refuses to rename or delete a name-matched category', function () {
    $user = User::factory()->create();
    app(UserCategoryProvisioner::class)->provision($user);
    $transfer = Category::query()->where('user_id', $user->id)->whereNull('parent_id')->where('name', 'Transfer')->sole();
    $salary = Category::query()->where('user_id', $user->id)->where('name', 'Salary')->sole();
    $groceries = Category::query()->where('user_id', $user->id)->where('name', 'Groceries')->sole();

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $transfer->id)
        ->set('editingName', 'Moves')
        ->call('saveRename')
        ->assertHasErrors('editingName')
        ->call('selectCategory', $transfer->id)
        ->call('selectCategory', $salary->id)
        ->set('editingParentId', $groceries->id)
        ->call('saveRename')
        ->assertHasErrors('editingName')
        ->call('confirmDelete', $transfer->id)
        ->assertHasErrors('deletingCategoryId')
        ->assertSet('showDeleteConfirm', false)
        ->set('deletingCategoryId', $transfer->id)
        ->call('deleteCategory')
        ->assertHasErrors('deletingCategoryId');

    expect($transfer->fresh()->name)->toBe('Transfer')
        ->and($salary->fresh()->parent_id)->not->toBe($groceries->id)
        ->and(Category::query()->whereKey($transfer->id)->exists())->toBeTrue();

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $groceries->id)
        ->set('editingName', 'Food shopping')
        ->call('saveRename')
        ->assertHasNoErrors();

    expect($groceries->fresh()->name)->toBe('Food shopping');
});

it('ignores another user\'s category and group ids sent through public properties', function () {
    [$ann, $ben] = User::factory()->count(2)->create()->all();
    app(UserCategoryProvisioner::class)->provision($ann);
    app(UserCategoryProvisioner::class)->provision($ben);
    $root = fn (User $user, string $name): Category => Category::query()->where('user_id', $user->id)->whereNull('parent_id')->where('name', $name)->sole();

    $annGroceries = $root($ann, 'Groceries');
    $benPets = $root($ben, 'Pets');
    $benGroup = CategoryGroup::query()->where('user_id', $ben->id)->firstOrFail();
    $benGroupName = $benGroup->name;

    $component = Livewire::actingAs($ann)->test(CategoryEditor::class);

    $component->set('deletingCategoryId', $benPets->id)->call('deleteCategory');
    $component->set('selectedCategoryId', $benPets->id)->set('editingName', 'Hijacked')->call('saveRename');
    $component->call('selectCategory', $annGroceries->id)->set('editingParentId', $benPets->id)->call('saveRename')->assertHasErrors('editingParentId');
    $component->set('editingGroupId', $benGroup->id)->call('saveCategoryGroup')->assertHasErrors('editingGroupId');
    $component->set('newCategoryName', 'Sneaky')->set('newParentId', $benPets->id)->call('createCategory')->assertHasErrors('newParentId');

    expect(Category::query()->whereKey($benPets->id)->value('name'))->toBe('Pets')
        ->and($annGroceries->fresh()->parent_id)->toBeNull()
        ->and($annGroceries->fresh()->group_id)->not->toBe($benGroup->id)
        ->and(Category::query()->where('name', 'Sneaky')->exists())->toBeFalse();

    expect(fn () => Livewire::actingAs($ann)->test(CategoryEditor::class)
        ->set('editingGroupRecordId', $benGroup->id)
        ->set('groupName', 'Hijacked group')
        ->set('groupColor', '#112233')
        ->set('groupIcon', 'gift')
        ->call('saveGroup'))->toThrow(ModelNotFoundException::class);

    expect($benGroup->fresh()->name)->toBe($benGroupName);
});

it('refuses a name or parent that would land on a name-matched path', function () {
    $user = User::factory()->create();
    app(UserCategoryProvisioner::class)->provision($user);
    $root = fn (string $name): Category => Category::query()->where('user_id', $user->id)->whereNull('parent_id')->where('name', $name)->sole();
    $pets = $root('Pets');
    $income = $root('Income');

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $pets->id)
        ->set('editingName', 'balance')
        ->call('saveRename')
        ->assertHasErrors('editingName')
        ->call('openCreateForm')
        ->set('newCategoryName', 'Transfer')
        ->set('newParentId', null)
        ->call('createCategory')
        ->assertHasErrors('newCategoryName')
        ->assertSee('cannot be created')
        ->set('newCategoryName', 'Salary')
        ->set('newParentId', $income->id)
        ->call('createCategory')
        ->assertHasErrors('newCategoryName')
        ->set('newCategoryName', 'Hobbies')
        ->set('newParentId', null)
        ->call('createCategory')
        ->assertHasNoErrors();

    expect($pets->fresh()->name)->toBe('Pets')
        ->and(Category::query()->where('user_id', $user->id)->where('name', 'Balance')->count())->toBe(1)
        ->and(Category::query()->where('user_id', $user->id)->where('name', 'Transfer')->whereNull('parent_id')->count())->toBe(1)
        ->and(Category::query()->where('user_id', $user->id)->where('name', 'Hobbies')->exists())->toBeTrue();
});

it('does not carry a refusal message over to the next category', function () {
    $user = User::factory()->create();
    app(UserCategoryProvisioner::class)->provision($user);
    $transfer = Category::query()->where('user_id', $user->id)->whereNull('parent_id')->where('name', 'Transfer')->sole();
    $groceries = Category::query()->where('user_id', $user->id)->whereNull('parent_id')->where('name', 'Groceries')->sole();

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('confirmDelete', $transfer->id)
        ->assertHasErrors('deletingCategoryId')
        ->call('selectCategory', $groceries->id)
        ->assertHasNoErrors()
        ->call('confirmDelete', $groceries->id)
        ->assertHasNoErrors()
        ->assertSet('showDeleteConfirm', true);
});

it('refuses to create a category while the user has locked categories', function () {
    $user = User::factory()->create();
    $user->updatePreferences(['categoriesLocked' => true]);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->set('newCategoryName', 'Sneaky')
        ->call('createCategory')
        ->assertHasErrors('newCategoryName');

    expect(Category::query()->where('user_id', $user->id)->where('name', 'Sneaky')->exists())->toBeFalse();
});

it('deactivates a rule that set a category when that category is deleted', function () {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create(['name' => 'Streaming']);
    $rule = App\Models\UserRule::factory()->for($user)->create([
        'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => 'NETFLIX']],
        'actions' => [['type' => 'set_category', 'value' => (string) $category->id]],
    ]);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('confirmDelete', $category->id)
        ->call('deleteCategory');

    expect(Category::query()->whereKey($category->id)->exists())->toBeFalse()
        ->and($rule->fresh()->is_active)->toBeFalse()
        ->and($rule->fresh()->actions)->toBe([]);
});

it('refuses to nest a category more than the allowed depth', function () {
    $user = User::factory()->create();
    $food = Category::factory()->for($user)->create(['name' => 'Food']);
    $coffee = Category::factory()->withParent($food)->create(['name' => 'Coffee']);
    $beans = Category::factory()->for($user)->create(['name' => 'Beans']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $beans->id)
        ->set('editingParentId', $coffee->id)
        ->call('saveRename')
        ->assertHasErrors('editingParentId')
        ->call('openCreateForm', $coffee->id)
        ->set('newCategoryName', 'Espresso')
        ->call('createCategory')
        ->assertHasErrors('newParentId');

    expect($beans->fresh()->parent_id)->toBeNull()
        ->and(Category::query()->where('name', 'Espresso')->exists())->toBeFalse();
});

it('refuses to move a category with subcategories under another category', function () {
    $user = User::factory()->create();
    $food = Category::factory()->for($user)->create(['name' => 'Food']);
    $home = Category::factory()->for($user)->create(['name' => 'Home']);
    Category::factory()->withParent($home)->create(['name' => 'Tools']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $home->id)
        ->set('editingParentId', $food->id)
        ->call('saveRename')
        ->assertHasErrors('editingParentId');

    expect($home->fresh()->parent_id)->toBeNull();
});

it('rejects an invalid colour or the reserved name', function () {
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create(['name' => 'Coffee', 'color' => '#112233']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $category->id)
        ->set('editingColor', 'red')
        ->call('saveRename')
        ->assertHasErrors('editingColor')
        ->set('editingColor', '#112233')
        ->set('editingName', '__uncategorized__')
        ->call('saveRename')
        ->assertHasErrors('editingName')
        ->call('openCreateForm')
        ->set('newCategoryName', '__Uncategorized__')
        ->call('createCategory')
        ->assertHasErrors('newCategoryName');

    expect($category->fresh()->name)->toBe('Coffee')
        ->and($category->fresh()->color)->toBe('#112233')
        ->and(Category::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('saves a category colour and icon and recolours its subcategories', function () {
    $user = User::factory()->create();
    $food = Category::factory()->for($user)->create(['name' => 'Food', 'color' => '#111111']);
    $coffee = Category::factory()->withParent($food)->create(['name' => 'Coffee', 'color' => '#111111']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $food->id)
        ->set('editingColor', '#abcdef')
        ->set('editingIcon', 'gift')
        ->call('saveRename')
        ->assertHasNoErrors();

    expect($food->fresh()->color)->toBe('#abcdef')
        ->and($food->fresh()->icon)->toBe('gift')
        ->and($coffee->fresh()->color)->toBe('#abcdef');
});

it('gives a subcategory its parent\'s colour when saved', function () {
    $user = User::factory()->create();
    $food = Category::factory()->for($user)->create(['name' => 'Food', 'color' => '#abcdef']);
    $coffee = Category::factory()->withParent($food)->create(['name' => 'Coffee', 'color' => '#111111']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $coffee->id)
        ->set('editingColor', '#222222')
        ->call('saveRename')
        ->assertHasNoErrors();

    expect($coffee->fresh()->color)->toBe('#abcdef');
});

it('merges categories from the editor and reports the affected transaction count', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $a = Category::factory()->for($user)->create(['name' => 'Alpha']);
    $b = Category::factory()->for($user)->create(['name' => 'Beta']);
    Transaction::factory()->for($user)->for($account)->count(3)->create(['category_id' => $b->id]);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $b->id)
        ->call('openMergeForm')
        ->assertSet('mergeSourceIds', [$b->id])
        ->assertSeeText('3 transactions will move to the target category.')
        ->set('mergeTargetId', $a->id)
        ->call('mergeCategories')
        ->assertHasNoErrors()
        ->assertSet('showMergeForm', false)
        ->assertSet('selectedCategoryId', null);

    expect(Category::query()->whereKey($b->id)->exists())->toBeFalse()
        ->and(Transaction::query()->where('category_id', $a->id)->count())->toBe(3);
});

it('shows a refused merge as an error and changes nothing', function () {
    $user = User::factory()->create();
    $parent = Category::factory()->for($user)->create(['name' => 'Food']);
    $child = Category::factory()->withParent($parent)->create(['name' => 'Coffee']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->set('mergeSourceIds', [$parent->id])
        ->set('mergeTargetId', $child->id)
        ->call('mergeCategories')
        ->assertHasErrors('mergeSourceIds');

    expect(Category::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('ignores another user\'s categories sent as merge ids', function () {
    $user = User::factory()->create();
    $mine = Category::factory()->for($user)->create(['name' => 'Mine']);
    $theirs = Category::factory()->for(User::factory()->create())->create(['name' => 'Theirs']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->set('mergeSourceIds', [$theirs->id])
        ->set('mergeTargetId', $mine->id)
        ->call('mergeCategories')
        ->assertHasErrors('mergeSourceIds.0');

    expect(Category::query()->whereKey($theirs->id)->exists())->toBeTrue();
});

it('records an audit event when categories are merged', function () {
    $user = User::factory()->create();
    $a = Category::factory()->for($user)->create(['name' => 'Alpha']);
    $b = Category::factory()->for($user)->create(['name' => 'Beta']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->set('mergeSourceIds', [$b->id])
        ->set('mergeTargetId', $a->id)
        ->call('mergeCategories');

    $event = AuditEvent::query()->where('action', 'livewire.category-editor.mergeCategories')->sole();

    expect($event->user_id)->toBe($user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

it('deletes with a replacement from the editor and moves the transactions there', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $doomed = Category::factory()->for($user)->create(['name' => 'Doomed']);
    $replacement = Category::factory()->for($user)->create(['name' => 'Replacement']);
    Transaction::factory()->for($user)->for($account)->count(2)->create(['category_id' => $doomed->id]);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('selectCategory', $doomed->id)
        ->call('confirmDelete', $doomed->id)
        ->set('deletingReplacementId', $replacement->id)
        ->assertSeeText('2 transactions will move to the replacement category.')
        ->call('deleteCategory')
        ->assertHasNoErrors();

    expect(Category::query()->whereKey($doomed->id)->exists())->toBeFalse()
        ->and(Transaction::query()->where('category_id', $replacement->id)->count())->toBe(2);
});

it('refuses another user\'s category as a delete replacement', function () {
    $user = User::factory()->create();
    $doomed = Category::factory()->for($user)->create(['name' => 'Doomed']);
    $theirs = Category::factory()->for(User::factory()->create())->create(['name' => 'Theirs']);

    Livewire::actingAs($user)
        ->test(CategoryEditor::class)
        ->call('confirmDelete', $doomed->id)
        ->set('deletingReplacementId', $theirs->id)
        ->call('deleteCategory')
        ->assertHasErrors('deletingReplacementId');

    expect(Category::query()->whereKey($doomed->id)->exists())->toBeTrue();
});

it('switches the category lock on and off for the signed-in user only', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $component = Livewire::actingAs($user)->test(CategoryEditor::class)->call('toggleCategoriesLocked');

    expect($user->fresh()->preferences->categoriesLocked)->toBeTrue()
        ->and($other->fresh()->preferences->categoriesLocked)->toBeFalse();

    $component->call('toggleCategoriesLocked');

    expect($user->fresh()->preferences->categoriesLocked)->toBeFalse();
});
