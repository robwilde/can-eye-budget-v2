<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\User;
use App\Services\UserCategoryProvisioner;

function provisionedCategory(User $user, string $path): Category
{
    return Category::allWithLinkedParents($user->id)
        ->first(fn (Category $category): bool => $category->fullPath() === $path)
        ?? throw new RuntimeException("Category '{$path}' was not provisioned");
}

it('groups every root except Balance and routes the savings transfers to Savings & Investments', function () {
    $user = User::factory()->create();

    app(UserCategoryProvisioner::class)->provision($user);

    $roots = Category::query()->where('user_id', $user->id)->whereNull('parent_id')->get();
    $savings = CategoryGroup::query()->where('user_id', $user->id)->where('name', UserCategoryProvisioner::SAVINGS_GROUP)->sole();

    expect($roots->whereNull('group_id')->pluck('name')->all())->toBe(['Balance'])
        ->and(provisionedCategory($user, 'Groceries')->effectiveGroup()->name)->toBe('Food')
        ->and(provisionedCategory($user, 'Transfer / Optimus to Spaceship')->group_id)->toBe($savings->id)
        ->and(provisionedCategory($user, 'Transfer / uBank to uSavings')->group_id)->toBe($savings->id)
        ->and(provisionedCategory($user, 'Transfer / Optimus to CC')->effectiveGroup()->name)->toBe('Transfer');
});

it('provisions nothing twice and never touches another user', function () {
    [$user, $other] = User::factory()->count(2)->create()->all();
    $provisioner = app(UserCategoryProvisioner::class);

    $provisioner->provision($user);
    $categories = Category::query()->where('user_id', $user->id)->count();
    $groups = CategoryGroup::query()->where('user_id', $user->id)->count();
    $provisioner->provision($user);

    expect(Category::query()->where('user_id', $user->id)->count())->toBe($categories)
        ->and(CategoryGroup::query()->where('user_id', $user->id)->count())->toBe($groups)
        ->and(Category::query()->where('user_id', $other->id)->exists())->toBeFalse()
        ->and(CategoryGroup::query()->where('user_id', $other->id)->exists())->toBeFalse();
});

it('reuses a default-named group the user already made instead of failing', function () {
    $user = User::factory()->create();
    $mine = CategoryGroup::factory()->for($user)->create(['name' => 'Food']);

    app(UserCategoryProvisioner::class)->provision($user);

    expect(CategoryGroup::query()->where('user_id', $user->id)->where('name', 'Food')->count())->toBe(1)
        ->and(provisionedCategory($user, 'Groceries')->group_id)->toBe($mine->id);
});
