<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('applies the mcard base + tone modifier', function (string $tone) {
    $html = Blade::render(
        '<x-cib.money-card label="Owed" :amount="12345" tone="'.$tone.'" />'
    );

    expect($html)->toContain('mcard '.$tone);
})->with(['owed', 'available', 'needed']);

it('renders label, amount and hint', function () {
    $html = Blade::render(
        '<x-cib.money-card label="NEEDED" :amount="24900" hint="by next payday" />'
    );

    expect($html)
        ->toContain('class="mlabel"')
        ->toContain('NEEDED')
        ->toContain('class="mmoney"')
        ->toContain('$249.00')
        ->toContain('class="mhint"')
        ->toContain('by next payday');
});

it('formats the amount as money from integer cents', function () {
    $html = Blade::render('<x-cib.money-card label="Owed" :amount="9990" />');

    expect($html)->toContain('$99.90');
});

it('omits the .mhint element when hint is null', function () {
    $html = Blade::render('<x-cib.money-card label="Owed" :amount="100" />');

    expect($html)->not->toContain('class="mhint"');
});

it('renders the badge slot content inside .mbadge', function () {
    $html = Blade::render(<<<'BLADE'
        <x-cib.money-card label="Owed" :amount="100">
            <x-slot:badge>
                <svg data-badge="wallet"></svg>
            </x-slot:badge>
        </x-cib.money-card>
    BLADE);

    expect($html)
        ->toContain('class="mbadge"')
        ->toContain('data-badge="wallet"');
});
