<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('renders a root stat-pill element', function () {
    $html = Blade::render('<x-cib.stat-pill label="IN" value="$10" />');

    expect($html)->toContain('stat-pill');
});

it('applies the tone modifier class', function (string $tone) {
    $html = Blade::render('<x-cib.stat-pill tone="'.$tone.'" label="X" value="Y" />');

    expect($html)->toContain('stat-pill-'.$tone);
})->with([
    'income',
    'posted',
    'planned',
    'buffer-pos',
    'buffer-neg',
    'buffer-empty',
    'neutral',
]);

it('defaults to the neutral tone when no tone is supplied', function () {
    $html = Blade::render('<x-cib.stat-pill label="N" value="3" />');

    expect($html)->toContain('stat-pill-neutral');
});

it('renders the label inside a pill-label span', function () {
    $html = Blade::render('<x-cib.stat-pill label="IN" value="$10" />');

    expect($html)
        ->toContain('class="pill-label"')
        ->toContain('IN');
});

it('renders the value inside a pill-value span', function () {
    $html = Blade::render('<x-cib.stat-pill label="IN" value="$10" />');

    expect($html)
        ->toContain('class="pill-value"')
        ->toContain('$10');
});

it('omits the pill-label span when no label is provided', function () {
    $html = Blade::render('<x-cib.stat-pill value="$10" />');

    expect($html)->not->toContain('class="pill-label"');
});

it('omits the pill-value span when no value is provided', function () {
    $html = Blade::render('<x-cib.stat-pill label="IN" />');

    expect($html)->not->toContain('class="pill-value"');
});

it('merges caller-supplied attributes onto the root element', function () {
    $html = Blade::render('<x-cib.stat-pill data-testid="pill" label="IN" value="$10" />');

    expect($html)->toContain('data-testid="pill"');
});
