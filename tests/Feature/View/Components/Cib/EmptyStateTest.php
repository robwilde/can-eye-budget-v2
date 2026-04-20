<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('renders a root empty-state wrapper', function () {
    $html = Blade::render('<x-cib.empty-state title="Nothing here" />');

    expect($html)->toContain('class="empty-state"');
});

it('renders the title inside a heading', function () {
    $html = Blade::render('<x-cib.empty-state title="No transactions" />');

    expect($html)->toContain('No transactions');
});

it('renders the description when provided', function () {
    $html = Blade::render(
        '<x-cib.empty-state title="No transactions" description="Add your first transaction." />'
    );

    expect($html)->toContain('Add your first transaction.');
});

it('omits the description paragraph when description is null', function () {
    $html = Blade::render('<x-cib.empty-state title="No transactions" />');

    expect($html)->not->toContain('Add your first transaction.');
});

it('renders a flux icon when the icon prop is set', function () {
    $html = Blade::render('<x-cib.empty-state icon="banknotes" title="No transactions" />');

    expect($html)->toMatch('/<svg[^>]*data-flux-icon/i');
});

it('omits the icon svg when no icon prop is provided', function () {
    $html = Blade::render('<x-cib.empty-state title="No transactions" />');

    expect($html)->not->toMatch('/<svg[^>]*data-flux-icon/i');
});

it('renders the action slot inside an empty-state-action wrapper', function () {
    $html = Blade::render(<<<'BLADE'
        <x-cib.empty-state title="No transactions">
            <x-slot:action>
                <button type="button">Add one</button>
            </x-slot:action>
        </x-cib.empty-state>
    BLADE);

    expect($html)
        ->toContain('class="empty-state-action"')
        ->toContain('Add one');
});

it('omits the empty-state-action wrapper when the slot is not provided', function () {
    $html = Blade::render('<x-cib.empty-state title="No transactions" />');

    expect($html)->not->toContain('empty-state-action');
});
