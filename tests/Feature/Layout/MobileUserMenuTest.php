<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(5),
    ]);
    $this->actingAs($this->user);
});

it('mobile More control exposes both settings and logout surfaces inside the tabbar region', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();

    $html = $response->getContent();

    preg_match('/<nav[^>]*data-testid="mobile-tabbar"[\s\S]*?<\/nav>/', $html, $matches);
    $tabbar = $matches[0] ?? '';

    expect($tabbar)->not->toBeEmpty();

    expect($tabbar)
        ->toContain(route('profile.edit'))
        ->toContain('action="'.route('logout').'"')
        ->toContain('data-test="mobile-logout-button"');
});

it('mobile More control keeps its More label inside the tabbar', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();

    $html = $response->getContent();

    expect($html)->toMatch('/data-testid="mobile-tabbar"[\s\S]*?\bMore\b[\s\S]*?<\/nav>/');
});

it('mobile More trigger carries aria-haspopup menu', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();

    $html = $response->getContent();

    expect($html)->toMatch('/data-testid="mobile-tabbar"[\s\S]*?aria-haspopup="menu"/');
});
