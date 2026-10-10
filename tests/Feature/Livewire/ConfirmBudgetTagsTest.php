<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BudgetTag;
use App\Livewire\ConfirmBudgetTags;
use App\Models\Category;
use App\Models\User;
use App\Models\UserCategoryBudgetTag;
use Livewire\Livewire;

function budgetRoot(string $name, ?BudgetTag $tag): Category
{
    return Category::factory()->create(['name' => $name, 'budget_tag' => $tag]);
}

test('tagged roots are listed under their default tag and untagged roots are left out', function () {
    budgetRoot('Groceries', BudgetTag::Needs);
    budgetRoot('Eating Out', BudgetTag::Wants);
    budgetRoot('Loans & Debt Repayment', BudgetTag::Savings);
    budgetRoot('Income', null);

    Livewire::actingAs(User::factory()->create())
        ->test(ConfirmBudgetTags::class)
        ->assertSeeInOrder(['Needs', 'Groceries', 'Wants', 'Eating Out', 'Savings', 'Loans & Debt Repayment'])
        ->assertDontSee('Income');
});

test('moving a category writes an override for this user only', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $root = budgetRoot('Groceries', BudgetTag::Needs);

    Livewire::actingAs($user)
        ->test(ConfirmBudgetTags::class)
        ->call('setTag', $root->id, BudgetTag::Wants->value);

    expect(UserCategoryBudgetTag::query()->where('user_id', $user->id)->sole()->budget_tag)->toBe(BudgetTag::Wants)
        ->and(UserCategoryBudgetTag::query()->where('user_id', $other->id)->exists())->toBeFalse();
});

test('a moved category is listed under its new tag', function () {
    $user = User::factory()->create();
    $root = budgetRoot('Groceries', BudgetTag::Needs);

    Livewire::actingAs($user)
        ->test(ConfirmBudgetTags::class)
        ->call('setTag', $root->id, BudgetTag::Savings->value)
        ->assertSeeInOrder(['Savings', 'Groceries']);
});

test('setting a category back to its default removes the override', function () {
    $user = User::factory()->create();
    $root = budgetRoot('Groceries', BudgetTag::Needs);

    Livewire::actingAs($user)
        ->test(ConfirmBudgetTags::class)
        ->call('setTag', $root->id, BudgetTag::Wants->value)
        ->call('setTag', $root->id, BudgetTag::Needs->value);

    expect(UserCategoryBudgetTag::query()->count())->toBe(0);
});

test('moving a category twice keeps a single override row', function () {
    $user = User::factory()->create();
    $root = budgetRoot('Groceries', BudgetTag::Needs);

    Livewire::actingAs($user)
        ->test(ConfirmBudgetTags::class)
        ->call('setTag', $root->id, BudgetTag::Wants->value)
        ->call('setTag', $root->id, BudgetTag::Savings->value);

    expect(UserCategoryBudgetTag::query()->where('user_id', $user->id)->sole()->budget_tag)->toBe(BudgetTag::Savings);
});

test('child categories, untagged roots and unknown tags are ignored', function () {
    $user = User::factory()->create();
    $root = budgetRoot('Groceries', BudgetTag::Needs);
    $child = Category::factory()->withParent($root)->create(['name' => 'Fruit']);
    $income = budgetRoot('Income', null);

    Livewire::actingAs($user)
        ->test(ConfirmBudgetTags::class)
        ->call('setTag', $child->id, BudgetTag::Wants->value)
        ->call('setTag', $income->id, BudgetTag::Wants->value)
        ->call('setTag', $root->id, 'bogus');

    expect(UserCategoryBudgetTag::query()->count())->toBe(0);
});

test('another user override does not change what this user sees', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $root = budgetRoot('Groceries', BudgetTag::Needs);
    UserCategoryBudgetTag::query()->create(['user_id' => $other->id, 'category_id' => $root->id, 'budget_tag' => BudgetTag::Savings]);

    $groups = Livewire::actingAs($user)->test(ConfirmBudgetTags::class)->instance()->groups();

    expect($groups[BudgetTag::Needs->value]->pluck('category.id')->all())->toBe([$root->id])
        ->and($groups[BudgetTag::Savings->value])->toBeEmpty();
});

test('confirming and skipping both announce the step is done and keep defaults', function (string $method) {
    $user = User::factory()->create();
    budgetRoot('Groceries', BudgetTag::Needs);

    Livewire::actingAs($user)
        ->test(ConfirmBudgetTags::class)
        ->call($method)
        ->assertDispatched('budget-tags-confirmed');

    expect(UserCategoryBudgetTag::query()->count())->toBe(0);
})->with(['confirm', 'skip']);
