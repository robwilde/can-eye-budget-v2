<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Category;
use App\Models\User;
use App\Services\FeedCategoryMatcher;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->matcher = new FeedCategoryMatcher;
});

function matcherCategory(User $user, string $name, ?Category $parent = null, array $overrides = []): Category
{
    return Category::factory()->for($user)->create([
        'name' => $name,
        'parent_id' => $parent?->id,
        'is_hidden' => false,
        ...$overrides,
    ]);
}

it('matches an exact full path ignoring case and separator style', function (string $value) {
    $home = matcherCategory($this->user, 'Home');
    $repairs = matcherCategory($this->user, 'Repairs', $home);

    expect($this->matcher->match($this->user->id, $value))->toBe($repairs->id);
})->with(['colon' => 'Home:Repairs', 'slash with spaces' => 'home / repairs', 'spacing' => '  HOME :  Repairs ']);

it('matches singular, plural and spacing variants as aliases', function (string $value) {
    $restaurants = matcherCategory($this->user, 'Eating Out');
    $target = matcherCategory($this->user, 'Restaurants', $restaurants);

    expect($this->matcher->match($this->user->id, $value))->toBe($target->id);
})->with([
    'singular' => 'Eating Out:Restaurant',
    'punctuation' => 'Eating-Out : Restaurants',
    'joined' => 'EatingOut/restaurant',
]);

it('falls back to the top-level category named by the parent segment', function () {
    $home = matcherCategory($this->user, 'Home');

    expect($this->matcher->match($this->user->id, 'Homes:Gardening'))->toBe($home->id);
});

it('returns null for a value with no path or alias match', function (?string $value) {
    matcherCategory($this->user, 'Groceries');

    expect($this->matcher->match($this->user->id, $value))->toBeNull();
})->with(['unknown' => 'Spaceships', 'blank' => '   ', 'null' => null]);

it('never matches another user category or a hidden one', function () {
    $other = User::factory()->create();
    matcherCategory($other, 'Groceries');
    matcherCategory($this->user, 'Fuel', overrides: ['is_hidden' => true]);

    expect($this->matcher->match($this->user->id, 'Groceries'))->toBeNull()
        ->and($this->matcher->match($this->user->id, 'Fuel'))->toBeNull();
});

it('does not create categories', function () {
    $before = Category::query()->count();

    $this->matcher->match($this->user->id, 'Brand New:Thing');

    expect(Category::query()->count())->toBe($before);
});
