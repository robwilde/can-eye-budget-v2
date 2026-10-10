<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Category;
use Database\Seeders\CategorySeeder;

beforeEach(function () {
    $this->seed(CategorySeeder::class);
});

it('assigns a non-null icon to every top-level category', function () {
    $topLevel = Category::query()->whereNull('parent_id')->get();

    expect($topLevel)->not->toBeEmpty();

    foreach ($topLevel as $category) {
        expect($category->icon)
            ->not->toBeNull()
            ->not->toBeEmpty();
    }
});

it('assigns the expected icon to each top-level category', function (string $name, string $icon) {
    $category = Category::query()->where('name', $name)->whereNull('parent_id')->first();

    expect($category)->not->toBeNull("Seeder missing top-level category '{$name}'")
        ->and($category->icon)->toBe($icon);
})->with([
    ['Housing & Utilities', 'home'],
    ['Groceries', 'shopping-cart'],
    ['Transport', 'home'],
    ['Health', 'activity'],
    ['Insurance', 'shield-check'],
    ['Software & Online Services', 'bolt'],
    ['Work Equipment', 'wrench-screwdriver'],
    ['Bank Fees & Finance Services', 'building-library'],
    ['Loans & Debt Repayment', 'building-library'],
    ['Eating Out', 'coffee'],
    ['Learning & Reading', 'book-open-text'],
    ['Entertainment', 'sparkles'],
    ['Pets', 'house-heart'],
    ['Personal & Shopping', 'sparkles'],
    ['Income', 'arrow-trending-up'],
    ['Transfer', 'building-library'],
    ['Balance', 'building-library'],
]);

it('overrides specific leaf categories with prototype-required icons', function (string $parentName, string $leafName, string $icon) {
    $leaf = Category::query()
        ->where('name', $leafName)
        ->whereHas('parent', fn ($q) => $q->where('name', $parentName))
        ->first();

    expect($leaf)->not->toBeNull("Leaf '{$parentName} / {$leafName}' not seeded")
        ->and($leaf->icon)->toBe($icon);
})->with([
    ['Housing & Utilities', 'Rent', 'house-heart'],
    ['Personal & Shopping', 'Kitchen', 'coffee'],
]);

it('seeds the 14 budget-tagged roots with their default tag and leaves Income, Transfer and Balance untagged', function () {
    $tags = Category::query()->whereNull('parent_id')->pluck('budget_tag', 'name')
        ->map(fn ($tag) => $tag?->value)
        ->all();

    expect($tags)->toEqual([
        'Housing & Utilities' => 'needs',
        'Groceries' => 'needs',
        'Transport' => 'needs',
        'Health' => 'needs',
        'Insurance' => 'needs',
        'Software & Online Services' => 'needs',
        'Work Equipment' => 'wants',
        'Bank Fees & Finance Services' => 'needs',
        'Loans & Debt Repayment' => 'savings',
        'Eating Out' => 'wants',
        'Learning & Reading' => 'wants',
        'Entertainment' => 'wants',
        'Pets' => 'wants',
        'Personal & Shopping' => 'wants',
        'Income' => null,
        'Transfer' => null,
        'Balance' => null,
    ]);
});

it('keeps the old leaves as children of their new root and never tags a child', function () {
    $paths = Category::allWithLinkedParents()->map(fn (Category $category): string => $category->fullPath());

    expect($paths)->toContain(
        'Software & Online Services / AI Apps',
        'Learning & Reading / Training / Course',
        'Work Equipment / Hardware / Rentals',
        'Eating Out / Quick Foods',
        'Bank Fees & Finance Services / Bank Fees',
        'Loans & Debt Repayment / Latitude / Interest',
        'Housing & Utilities / Rent',
        'Income / Salary',
        'Transfer / Optimus to CC',
    )->and(Category::query()->whereNotNull('parent_id')->whereNotNull('budget_tag')->exists())->toBeFalse();
});

it('only uses icon names that Flux can resolve (Heroicons allow-list or installed Lucide partials)', function () {
    $heroicons = [
        'home', 'shopping-cart', 'bolt', 'sparkles', 'calendar',
        'building-library', 'arrow-trending-up', 'shield-check', 'wrench-screwdriver',
    ];

    $lucide = collect(glob(resource_path('views/flux/icon/*.blade.php')))
        ->map(fn (string $path): string => basename($path, '.blade.php'))
        ->all();

    $allowed = array_merge($heroicons, $lucide);

    $usedIcons = Category::query()
        ->whereNotNull('icon')
        ->pluck('icon')
        ->unique()
        ->values()
        ->all();

    $unresolvable = array_values(array_diff($usedIcons, $allowed));

    expect($unresolvable)->toBeEmpty();
});
