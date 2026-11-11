<?php

/** @noinspection JSUnresolvedReference */
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

$openModal = <<<'JS'
    Livewire.dispatch('open-transaction-modal', { date: '2026-04-19' })
JS;

test('picker offers to create a category and selects it', function () use ($openModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    Category::factory()->for($user)->create(['name' => 'Groceries']);

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script($openModal);

    $page->assertPresent('[role="combobox"] input')
        ->click('[role="combobox"] input')
        ->type('[role="combobox"] input', 'Cafes')
        ->assertPresent('[data-testid="create-category"]')
        ->click('[data-testid="create-category"]')
        ->assertVisible('[aria-label="Clear selection"]')
        ->assertValue('[role="combobox"] input', 'Cafes');

    expect(Category::query()->where('user_id', $user->id)->where('name', 'Cafes')->exists())->toBeTrue();
});

test('picker shows the locked message instead of creating while categories are locked', function () use ($openModal) {
    $user = User::factory()->create();
    $user->updatePreferences(['categoriesLocked' => true]);
    Account::factory()->for($user)->create();

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script($openModal);

    $page->assertPresent('[role="combobox"] input')
        ->click('[role="combobox"] input')
        ->type('[role="combobox"] input', 'Cafes')
        ->click('[data-testid="create-category"]')
        ->assertSee('Categories are locked');

    expect(Category::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('picker lists the recently used categories when focused', function () use ($openModal) {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $recent = Category::factory()->for($user)->create(['name' => 'Zebra Supplies']);
    Category::factory()->for($user)->create(['name' => 'Unpicked Thing']);
    $user->updatePreferences(['recentCategoryIds' => [$recent->id]]);

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script($openModal);

    $page->assertPresent('[role="combobox"] input')
        ->click('[role="combobox"] input')
        ->assertSee('Recently used')
        ->assertSee('Zebra Supplies')
        ->assertDontSee('Unpicked Thing');
});

test('categories page merges a category into another and moves its transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $alpha = Category::factory()->for($user)->create(['name' => 'Alpha']);
    $beta = Category::factory()->for($user)->create(['name' => 'Beta']);
    Transaction::factory()->for($user)->for($account)->count(2)->create(['category_id' => $beta->id]);

    $this->actingAs($user);

    $page = visit('/accounts');

    $page->click('Beta')
        ->click('button[wire\\:click="openMergeForm"]')
        ->assertSee('Merge categories')
        ->assertSee('2 transactions will move to the target category.')
        ->select('#merge-target-select', (string) $alpha->id)
        ->click('button[wire\\:click="mergeCategories"]')
        ->assertDontSee('Merge categories');

    expect(Category::query()->whereKey($beta->id)->exists())->toBeFalse()
        ->and(Transaction::query()->where('category_id', $alpha->id)->count())->toBe(2);
});

test('picker shows a newly saved pick in the recent list without a reload', function () use ($openModal) {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Category::factory()->for($user)->create(['name' => 'Zebra Supplies']);

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script($openModal);

    $page->assertPresent('[role="combobox"] input')
        ->type('[role="combobox"] input', 'Zebra')
        ->click('[role="listbox"] button[role="option"]');

    $page->script("(() => { const modal = Livewire.getByName('transaction-modal')[0]; modal.set('descriptionInput', '12.50 feed'); modal.set('accountId', {$account->id}); modal.call('save'); })()");
    $page->wait(2);
    $page->script($openModal);

    $page->click('[role="combobox"] input')
        ->assertSee('Recently used');
});
