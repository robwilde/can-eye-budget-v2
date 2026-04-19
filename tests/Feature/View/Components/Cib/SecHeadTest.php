<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('renders the title inside an h3', function () {
    $html = Blade::render('<x-cib.sec-head title="Recent" />');

    expect($html)
        ->toContain('class="sec-head"')
        ->toContain('<h3>Recent</h3>');
});

it('omits the See all link when href is null', function () {
    $html = Blade::render('<x-cib.sec-head title="Recent" />');

    expect($html)->not->toContain('See all');
});

it('renders a See all link when href is provided', function () {
    $html = Blade::render('<x-cib.sec-head title="Recent" href="/transactions" />');

    expect($html)
        ->toContain('<a class="link" href="/transactions">')
        ->toContain('See all');
});
