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

it('renders a flux icon inside .tx-ico when the icon prop is set', function () {
    $html = Blade::render(
        '<x-cib.tx-row :transaction-id="1" name="Coffee" :amount="500" tone="out" icon="coffee" />'
    );

    expect($html)
        ->toContain('tx-ico out')
        ->toMatch('/<svg[^>]*data-flux-icon/i');
});

it('omits the icon svg when no icon prop is provided', function () {
    $html = Blade::render('<x-cib.tx-row :transaction-id="1" name="Coffee" :amount="500" />');

    expect($html)
        ->toContain('tx-ico')
        ->not->toMatch('/<svg[^>]*data-flux-icon/i');
});

it('dispatches open-reconciliation-modal when plannedTransactionId is set', function () {
    $html = Blade::render(
        '<x-cib.tx-row :planned-transaction-id="7" occurrence-date="2026-04-19" name="Rent" :amount="150000" tone="plan" />'
    );

    expect($html)
        ->toContain("wire:click=\"\$dispatch('open-reconciliation-modal', { plannedId: 7, occurrenceDate: '2026-04-19' })\"")
        ->toContain('tx-row planned');
});

it('JS-encodes occurrenceDate to neutralise apostrophe injection', function () {
    $html = Blade::render(
        '<x-cib.tx-row :planned-transaction-id="1" :occurrence-date="$date" name="Rent" :amount="100" />',
        ['date' => "'); alert(1); x=('"]
    );

    expect($html)
        ->not->toContain("'); alert(1)")
        ->toContain('\u0027');
});
