<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('renders a root cib-card wrapper', function () {
    $html = Blade::render('<x-cib.card>Body</x-cib.card>');

    expect($html)->toContain('class="cib-card"');
});

it('renders the default slot as the card body', function () {
    $html = Blade::render('<x-cib.card>Hello body</x-cib.card>');

    expect($html)
        ->toContain('class="cib-card-body"')
        ->toContain('Hello body');
});

it('applies the teal tone modifier class', function () {
    $html = Blade::render('<x-cib.card tone="teal">Body</x-cib.card>');

    expect($html)->toContain('cib-card teal');
});

it('applies the yellow tone modifier class', function () {
    $html = Blade::render('<x-cib.card tone="yellow">Body</x-cib.card>');

    expect($html)->toContain('cib-card yellow');
});

it('emits no tone modifier for the default tone', function () {
    $html = Blade::render('<x-cib.card>Body</x-cib.card>');

    expect($html)
        ->not->toContain('cib-card teal')
        ->not->toContain('cib-card yellow');
});

it('renders the header slot inside a cib-card-header wrapper', function () {
    $html = Blade::render(<<<'BLADE'
        <x-cib.card>
            <x-slot:header>My header</x-slot:header>
            Body
        </x-cib.card>
    BLADE);

    expect($html)
        ->toContain('class="cib-card-header"')
        ->toContain('My header');
});

it('omits the header wrapper when no header slot is supplied', function () {
    $html = Blade::render('<x-cib.card>Body</x-cib.card>');

    expect($html)->not->toContain('cib-card-header');
});

it('renders the footer slot inside a cib-card-footer wrapper', function () {
    $html = Blade::render(<<<'BLADE'
        <x-cib.card>
            Body
            <x-slot:footer>My footer</x-slot:footer>
        </x-cib.card>
    BLADE);

    expect($html)
        ->toContain('class="cib-card-footer"')
        ->toContain('My footer');
});

it('omits the footer wrapper when no footer slot is supplied', function () {
    $html = Blade::render('<x-cib.card>Body</x-cib.card>');

    expect($html)->not->toContain('cib-card-footer');
});

it('merges caller-supplied attributes onto the root element', function () {
    $html = Blade::render('<x-cib.card id="primary-card" data-testid="card">Body</x-cib.card>');

    expect($html)
        ->toContain('id="primary-card"')
        ->toContain('data-testid="card"');
});
