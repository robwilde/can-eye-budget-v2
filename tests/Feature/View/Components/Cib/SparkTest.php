<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('renders one .bar element per value', function () {
    $values = array_fill(0, 14, 5);

    $html = Blade::render('<x-cib.spark :values="$values" />', ['values' => $values]);

    expect(mb_substr_count($html, 'class="bar'))->toBe(14);
});

it('marks the first tallest bar as .big', function () {
    $values = [1, 2, 9, 3, 9, 1];

    $html = Blade::render('<x-cib.spark :values="$values" />', ['values' => $values]);

    expect(mb_substr_count($html, 'bar big'))->toBe(1);
});

it('marks bars at payday indexes as .big regardless of height', function () {
    $values = [1, 1, 9, 1, 1];
    $paydayIndexes = [0, 4];

    $html = Blade::render(
        '<x-cib.spark :values="$values" :payday-indexes="$paydayIndexes" />',
        ['values' => $values, 'paydayIndexes' => $paydayIndexes]
    );

    expect(mb_substr_count($html, 'bar big'))->toBe(3);
});

it('renders an inline height style on each bar', function () {
    $values = [2, 5, 10];

    $html = Blade::render('<x-cib.spark :values="$values" />', ['values' => $values]);

    expect(mb_substr_count($html, 'style="height:'))->toBe(3);
});

it('applies the spark root class', function () {
    $html = Blade::render('<x-cib.spark :values="[1, 2, 3]" />');

    expect($html)->toContain('class="spark"');
});

it('renders without bars and without throwing when values is empty', function () {
    $html = Blade::render('<x-cib.spark :values="[]" />');

    expect($html)->toContain('class="spark"');
    expect(mb_substr_count($html, 'class="bar'))->toBe(0);
});
