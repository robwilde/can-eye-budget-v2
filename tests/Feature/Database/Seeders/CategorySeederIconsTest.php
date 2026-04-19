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
    ['Office', 'bolt'],
    ['Personal', 'sparkles'],
    ['Entertainment', 'sparkles'],
    ['Food', 'shopping-cart'],
    ['Bills', 'activity'],
    ['Income', 'arrow-trending-up'],
    ['Transfer', 'building-library'],
    ['Loan', 'building-library'],
    ['Transport', 'home'],
]);

it('overrides specific leaf categories with prototype-required icons', function (string $parentName, string $leafName, string $icon) {
    $leaf = Category::query()
        ->where('name', $leafName)
        ->whereHas('parent', fn ($q) => $q->where('name', $parentName))
        ->first();

    expect($leaf)->not->toBeNull("Leaf '{$parentName} / {$leafName}' not seeded")
        ->and($leaf->icon)->toBe($icon);
})->with([
    ['Bills', 'Rent', 'house-heart'],
    ['Personal', 'Kitchen', 'coffee'],
]);

it('only uses icon names that Flux can resolve (Heroicons allow-list or installed Lucide partials)', function () {
    $heroicons = [
        'home', 'shopping-cart', 'bolt', 'sparkles', 'calendar',
        'building-library', 'arrow-trending-up',
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
