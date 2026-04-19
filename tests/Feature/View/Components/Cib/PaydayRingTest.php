<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('shows "It\'s payday" when days is zero', function () {
    $html = Blade::render('<x-cib.payday-ring :days="0" />');

    expect($html)
        ->toContain('class="payday-ring"')
        ->toContain('class="pr"')
        ->toContain('0')
        ->toContain("It's payday");
});

it('shows the days-to-payday label when days is positive', function () {
    $html = Blade::render('<x-cib.payday-ring :days="5" />');

    expect($html)
        ->toContain('class="payday-ring"')
        ->toContain('class="pr"')
        ->toContain('>5<')
        ->toContain('days to payday');
});
