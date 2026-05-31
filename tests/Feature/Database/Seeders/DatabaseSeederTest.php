<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Budget;
use App\Models\Category;
use Database\Seeders\DatabaseSeeder;

test('the database seeder creates no budgets', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Budget::count())->toBe(0);
});

test('the database seeder still seeds the category tree', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Category::count())->toBeGreaterThan(0)
        ->and(Category::query()->whereNull('parent_id')->where('name', 'Income')->exists())->toBeTrue();
});
