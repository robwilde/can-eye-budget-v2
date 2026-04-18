<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\BasiqRefreshLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->withPayCycle()->create([
        'next_pay_date' => now()->addDays(5),
    ]);
    $this->actingAs($this->user);
});

it('renders the new teal sidebar with brand block', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('Can I Budget', false);
    $response->assertSee('AU · Personal', false);
    $response->assertSee('bg-cib-teal-400', false);
});

it('shows a payday chip with days until next pay', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('5d', false);
    $response->assertSee('until next payday', false);
});

it('hides the payday chip when pay cycle is not configured', function () {
    $user = User::factory()->create([
        'pay_amount' => null,
        'pay_frequency' => null,
        'next_pay_date' => null,
    ]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('until next payday', false);
});

it('shows a sync chip reflecting the latest BasiqRefreshLog', function () {
    $log = BasiqRefreshLog::factory()
        ->for($this->user)
        ->completed()
        ->create();
    $log->forceFill(['created_at' => now()->subMinutes(3)])->save();

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('Synced', false);
    $response->assertSee('Basiq', false);
});

it('renders the mobile tabbar only below lg', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('data-testid="mobile-tabbar"', false);
    $response->assertSee('lg:hidden', false);
});

it('wires the FAB to dispatch open-transaction-modal', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('open-transaction-modal', false);
    $response->assertSee('data-testid="mobile-tabbar-fab"', false);
});

it('exposes the Log spend CTA in the topbar', function () {
    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('Log spend', false);
    $response->assertSee('data-testid="topbar-log-spend"', false);
});

it('queries basiq_refresh_logs at most once per request for the layout shell', function () {
    BasiqRefreshLog::factory()->for($this->user)->completed()->create();

    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'basiq_refresh_logs')) {
            $queries++;
        }
    });

    $this->get(route('dashboard'))->assertOk();

    expect($queries)->toBeLessThanOrEqual(1);
});
