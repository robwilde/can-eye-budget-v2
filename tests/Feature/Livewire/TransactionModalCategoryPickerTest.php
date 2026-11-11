<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecentCategoryPicks;
use App\Services\RuleActionExecutor;
use Livewire\Features\SupportTesting\Testable;

function pickerModal(User $user): Testable
{
    return Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-03-15');
}

function pickerSave(User $user, Account $account, string $description, ?int $categoryId): void
{
    pickerModal($user)
        ->set('descriptionInput', $description)
        ->set('accountId', $account->id)
        ->set('categoryId', $categoryId)
        ->call('save')
        ->assertHasNoErrors();
}

it('shows the locked message and creates nothing while categories are locked', function () {
    $user = User::factory()->create();
    $user->updatePreferences(['categoriesLocked' => true]);

    pickerModal($user)
        ->call('createCategoryFromPicker', 'Brand new')
        ->assertHasErrors('categoryCreate')
        ->assertSee('Categories are locked')
        ->assertSet('categoryId', null);

    expect(Category::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('assigns a category created from the picker to the transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $component = pickerModal($user)
        ->set('descriptionInput', '12.50 flat white')
        ->set('accountId', $account->id)
        ->call('createCategoryFromPicker', 'Cafes')
        ->assertHasNoErrors();

    $category = Category::query()->where('user_id', $user->id)->where('name', 'Cafes')->sole();

    $component->assertSet('categoryId', $category->id)->call('save')->assertHasNoErrors();

    expect(Transaction::query()->where('user_id', $user->id)->sole()->category_id)->toBe($category->id)
        ->and($category->parent_id)->toBeNull();
});

it('refuses a picker category with a reserved, duplicate or blank name', function () {
    $user = User::factory()->create();
    Category::factory()->for($user)->create(['name' => 'Cafes']);

    pickerModal($user)
        ->call('createCategoryFromPicker', '__uncategorized__')
        ->assertHasErrors('categoryCreate')
        ->call('createCategoryFromPicker', 'cafes')
        ->assertHasErrors('categoryCreate')
        ->call('createCategoryFromPicker', '   ')
        ->assertHasErrors('categoryCreate')
        ->call('createCategoryFromPicker', 'Transfer')
        ->assertHasErrors('categoryCreate');

    expect(Category::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('shows only the users own recent manual picks', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $categories = collect(['One', 'Two', 'Three', 'Four', 'Five', 'Six'])
        ->map(fn (string $name): Category => Category::factory()->for($user)->create(['name' => $name]));

    foreach ($categories->take(5) as $index => $category) {
        pickerSave($user, $account, '5 pick '.$index, $category->id);
    }

    $ruleTransaction = Transaction::factory()->for($user)->for($account)->create(['category_id' => null]);
    app(RuleActionExecutor::class)->execute($ruleTransaction, [['type' => 'set_category', 'value' => (string) $categories[5]->id]]);

    $recent = pickerModal($user)->viewData('recentCategories');

    expect($ruleTransaction->fresh()->category_id)->toBe($categories[5]->id)
        ->and(array_column($recent, 'id'))->toBe([$categories[4]->id, $categories[3]->id, $categories[2]->id, $categories[1]->id])
        ->and($user->fresh()->preferences->recentCategoryIds)->toBe([$categories[4]->id, $categories[3]->id, $categories[2]->id, $categories[1]->id]);
});

it('does not record a pick when the category is unchanged or left empty', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->for($user)->create(['name' => 'One']);
    $transaction = Transaction::factory()->for($user)->for($account)->create(['category_id' => $category->id]);

    pickerSave($user, $account, '5 nothing', null);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $transaction->id)
        ->call('save');

    expect($user->fresh()->preferences->recentCategoryIds)->toBe([]);
});

it('keeps another user\'s and hidden categories out of the recent shortcut', function () {
    $user = User::factory()->create();
    $hidden = Category::factory()->for($user)->create(['name' => 'Hidden', 'is_hidden' => true]);
    $visible = Category::factory()->for($user)->create(['name' => 'Visible']);
    $theirs = Category::factory()->for(User::factory()->create())->create(['name' => 'Theirs']);
    $user->updatePreferences(['recentCategoryIds' => [$theirs->id, $hidden->id, $visible->id, 999999]]);

    expect(app(RecentCategoryPicks::class)->categoriesFor($user->fresh())->pluck('id')->all())->toBe([$visible->id]);

    app(RecentCategoryPicks::class)->record($user, $theirs->id);

    expect($user->fresh()->preferences->recentCategoryIds)->toBe([$theirs->id, $hidden->id, $visible->id, 999999]);
});

it('keeps at most four picks, most recent first, without duplicates', function () {
    $user = User::factory()->create();
    $picks = app(RecentCategoryPicks::class);
    $ids = collect(range(1, 5))->map(fn (int $n): int => Category::factory()->for($user)->create(['name' => 'Cat '.$n])->id);

    foreach ($ids as $id) {
        $picks->record($user, $id);
    }

    $picks->record($user, $ids[2]);

    expect($user->fresh()->preferences->recentCategoryIds)->toBe([$ids[2], $ids[4], $ids[3], $ids[1]]);
});
