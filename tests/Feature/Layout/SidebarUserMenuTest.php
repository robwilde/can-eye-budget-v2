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

it('renders the trigger with data-test sidebar-menu-button and the user name', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('data-test="sidebar-menu-button"', false);
    $response->assertSee($this->user->name, false);
});

it('exposes a settings link to route profile edit', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee(route('profile.edit'), false);
});

it('exposes a logout form targeting route logout with data-test sidebar-logout-button', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('action="'.route('logout').'"', false);
    $response->assertSee('method="POST"', false);
    $response->assertSee('data-test="sidebar-logout-button"', false);
});

it('keeps the label wrapper hidden in collapsed desktop state', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('in-data-flux-sidebar-collapsed-desktop:hidden', false);
});

it('renders the user menu on every authed shell route', function (string $routeName) {
    $response = $this->get(route($routeName));

    $response->assertOk();
    $response->assertSee('data-test="sidebar-menu-button"', false);
    $response->assertSee('data-test="sidebar-logout-button"', false);
})->with([
    'dashboard',
    'accounts',
    'transactions',
    'calendar',
    'connect-bank',
    'rules',
]);

it('renders the trigger as a real button with an accessible name', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();

    $html = $response->getContent();

    expect($html)->toMatch(
        '/<button[^>]*data-test="sidebar-menu-button"[\s\S]*?'
        .preg_quote(e($this->user->name), '/')
        .'[\s\S]*?<\/button>/'
    );
});

it('renders the logout control as a submit button inside a CSRF-protected POST form', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();

    $html = $response->getContent();

    expect($html)
        ->toContain('method="POST"')
        ->toContain('action="'.route('logout').'"')
        ->toContain('name="_token"');

    expect($html)->toMatch(
        '/type="submit"[^>]*data-test="sidebar-logout-button"|data-test="sidebar-logout-button"[^>]*type="submit"/'
    );
});

it('renders a real aria-label attribute on the sidebar trigger button', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();

    $html = $response->getContent();

    expect($html)->toMatch(
        '/<button[^>]*data-test="sidebar-menu-button"[^>]*aria-label="'
        .preg_quote(e($this->user->name), '/')
        .'"|<button[^>]*aria-label="'
        .preg_quote(e($this->user->name), '/')
        .'"[^>]*data-test="sidebar-menu-button"/'
    );

    expect($html)->not->toContain(':aria-label=');
});
