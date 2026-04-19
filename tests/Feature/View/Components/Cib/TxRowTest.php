<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('renders the root tx-row class', function () {
    $html = Blade::render('<x-cib.tx-row :transaction-id="1" name="Coffee" :amount="500" />');

    expect($html)->toContain('class="tx-row"');
});

it('renders as a button element for keyboard accessibility', function () {
    $html = Blade::render('<x-cib.tx-row :transaction-id="1" name="Coffee" :amount="500" />');

    expect($html)
        ->toContain('<button type="button"')
        ->toContain('</button>');
});

it('applies the correct tone class to icon and amount', function (string $tone) {
    $html = Blade::render(
        '<x-cib.tx-row :transaction-id="1" name="Coffee" :amount="500" tone="'.$tone.'" />'
    );

    expect($html)
        ->toContain('tx-ico '.$tone)
        ->toContain('tx-amt '.$tone);
})->with(['out', 'inc', 'plan']);

it('formats the amount as money from integer cents', function () {
    $html = Blade::render('<x-cib.tx-row :transaction-id="1" name="Coffee" :amount="1250" />');

    expect($html)->toContain('$12.50');
});

it('dispatches edit-transaction with the integer id on click', function () {
    $html = Blade::render('<x-cib.tx-row :transaction-id="42" name="Coffee" :amount="500" />');

    expect($html)->toContain(
        "wire:click=\"\$dispatch('edit-transaction', { id: 42 })\""
    );
});

it('renders meta slot content inside .tx-meta', function () {
    $html = Blade::render(<<<'BLADE'
        <x-cib.tx-row :transaction-id="1" name="Coffee" :amount="500">
            <x-slot:meta>Today, 9am</x-slot:meta>
        </x-cib.tx-row>
    BLADE);

    expect($html)
        ->toContain('class="tx-meta"')
        ->toContain('Today, 9am');
});

it('omits the .tx-meta element when meta slot is not provided', function () {
    $html = Blade::render('<x-cib.tx-row :transaction-id="1" name="Coffee" :amount="500" />');

    expect($html)->not->toContain('class="tx-meta"');
});

it('renders icon slot content inside .tx-ico', function () {
    $html = Blade::render(<<<'BLADE'
        <x-cib.tx-row :transaction-id="1" name="Coffee" :amount="500" tone="out">
            <x-slot:icon>
                <svg data-icon="coffee"></svg>
            </x-slot:icon>
        </x-cib.tx-row>
    BLADE);

    expect($html)->toContain('data-icon="coffee"');
});
