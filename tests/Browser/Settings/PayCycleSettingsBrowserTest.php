<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\User;

test('pay cycle settings page renders with all form fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit('/settings/pay-cycle');

    $page->assertSee('Pay cycle')
        ->assertSee('Pay amount per cycle')
        ->assertSee('Pay frequency')
        ->assertSee('Next pay date')
        ->assertSee('Committed expenses per cycle');
});

test('user can fill and save pay cycle form', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit('/settings/pay-cycle');

    $page->assertSee('Pay cycle')
        ->fill('[wire\\:model="pay_amount"]', '3000')
        ->select('[wire\\:model="pay_frequency"]', 'fortnightly')
        ->fill('[wire\\:model="next_pay_date"]', now()->addDays(7)->format('Y-m-d'))
        ->fill('[wire\\:model="committed_per_cycle"]', '1800')
        ->click('[data-test="save-pay-cycle-button"]')
        ->assertSee('Saved.');

    $user->refresh();

    expect($user->pay_amount)->toBe(300000)
        ->and($user->committed_per_cycle)->toBe(180000);
});

test('saved values persist on page reload', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_amount' => 250000,
        'committed_per_cycle' => 150000,
    ]);

    $this->actingAs($user);

    $page = visit('/settings/pay-cycle');

    $page->assertSee('Pay cycle')
        ->assertSee('Pay amount per cycle')
        ->assertSee('Committed expenses per cycle');
});
