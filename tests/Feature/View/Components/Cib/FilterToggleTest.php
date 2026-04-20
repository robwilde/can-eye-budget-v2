<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

function filter_toggle_options(): array
{
    return [
        ['value' => 'all', 'label' => 'All',      'tone' => null],
        ['value' => 'inc', 'label' => 'Incoming', 'tone' => 'inc'],
        ['value' => 'out', 'label' => 'Outgoing', 'tone' => 'out'],
    ];
}

it('renders a root type-toggle wrapper', function () {
    $html = Blade::render(
        '<x-cib.filter-toggle :options="$options" selected="all" wire-model="filter" />',
        ['options' => filter_toggle_options()]
    );

    expect($html)->toContain('class="type-toggle"');
});

it('renders a button for each option', function () {
    $html = Blade::render(
        '<x-cib.filter-toggle :options="$options" selected="all" wire-model="filter" />',
        ['options' => filter_toggle_options()]
    );

    expect($html)
        ->toContain('All')
        ->toContain('Incoming')
        ->toContain('Outgoing');

    expect(mb_substr_count($html, '<button'))->toBe(3);
});

it('marks the selected option button as active', function () {
    $html = Blade::render(
        '<x-cib.filter-toggle :options="$options" selected="inc" wire-model="filter" />',
        ['options' => filter_toggle_options()]
    );

    expect($html)->toContain('class="active inc"');
});

it('does not add the active class to non-selected options', function () {
    $html = Blade::render(
        '<x-cib.filter-toggle :options="$options" selected="all" wire-model="filter" />',
        ['options' => filter_toggle_options()]
    );

    expect(mb_substr_count($html, 'active'))->toBe(1);
});

it('applies the tone class only to the active button', function () {
    $html = Blade::render(
        '<x-cib.filter-toggle :options="$options" selected="out" wire-model="filter" />',
        ['options' => filter_toggle_options()]
    );

    expect($html)->toContain('class="active out"');
    expect($html)->not->toContain('class="inc"');
});

it('wires click to $set on the supplied wireModel target', function () {
    $html = Blade::render(
        '<x-cib.filter-toggle :options="$options" selected="all" wire-model="filter" />',
        ['options' => filter_toggle_options()]
    );

    expect($html)
        ->toContain("wire:click=\"\$set('filter', 'all')\"")
        ->toContain("wire:click=\"\$set('filter', 'inc')\"")
        ->toContain("wire:click=\"\$set('filter', 'out')\"");
});

it('renders buttons as type=button for form safety', function () {
    $html = Blade::render(
        '<x-cib.filter-toggle :options="$options" selected="all" wire-model="filter" />',
        ['options' => filter_toggle_options()]
    );

    expect(mb_substr_count($html, 'type="button"'))->toBe(3);
});
