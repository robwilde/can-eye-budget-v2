<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('renders the row, track and fill elements', function () {
    $html = Blade::render('<x-cib.budget-row name="Food" :spent="5000" :limit="20_000" />');

    expect($html)
        ->toContain('class="budget-row"')
        ->toContain('class="track"')
        ->toContain('class="fill"');
});

it('applies .over to the fill when spent exceeds limit', function (int $spent, int $limit) {
    $html = Blade::render(
        '<x-cib.budget-row name="Food" :spent="'.$spent.'" :limit="'.$limit.'" />'
    );

    expect($html)->toContain('fill over');
})->with([
    'over limit' => [25_000, 20_000],
]);

it('omits .over from the fill when spent does not exceed limit', function (int $spent, int $limit) {
    $html = Blade::render(
        '<x-cib.budget-row name="Food" :spent="'.$spent.'" :limit="'.$limit.'" />'
    );

    expect($html)->toContain('class="fill"');
    expect($html)->not->toContain('fill over');
})->with([
    'under limit' => [5000, 20_000],
    'at limit' => [20_000, 20_000],
]);

it('caps the fill width at 100%', function () {
    $html = Blade::render('<x-cib.budget-row name="Food" :spent="50_000" :limit="20_000" />');

    expect($html)->toContain('width: 100%');
});

it('computes the fill width from the spent/limit ratio', function () {
    $html = Blade::render('<x-cib.budget-row name="Food" :spent="5000" :limit="20_000" />');

    expect($html)->toContain('width: 25%');
});

it('renders formatted spent/limit money inside .amt', function () {
    $html = Blade::render('<x-cib.budget-row name="Food" :spent="5000" :limit="20_000" />');

    expect($html)
        ->toContain('class="amt"')
        ->toContain('<b>$50.00</b>')
        ->toContain('/ $200.00');
});
