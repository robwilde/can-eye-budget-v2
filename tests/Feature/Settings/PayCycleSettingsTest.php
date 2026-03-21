<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PayFrequency;
use App\Models\User;
use Livewire\Livewire;

test('pay cycle settings page is displayed', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('pay-cycle.edit'))->assertOk();
});

test('unauthenticated user is redirected from pay cycle settings', function () {
    $this->get(route('pay-cycle.edit'))->assertRedirect(route('login'));
});

test('user can save pay cycle settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.pay-cycle')
        ->set('pay_amount', '3000.00')
        ->set('pay_frequency', 'fortnightly')
        ->set('next_pay_date', now()->addDays(7)->format('Y-m-d'))
        ->call('save');

    $response->assertHasNoErrors()
        ->assertDispatched('pay-cycle-updated');

    $user->refresh();

    expect($user->pay_amount)->toBe(300000)
        ->and($user->pay_frequency)->toBe(PayFrequency::Fortnightly)
        ->and($user->next_pay_date)->not->toBeNull();
});

test('dollar inputs correctly convert to cents in database', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.pay-cycle')
        ->set('pay_amount', '1234.56')
        ->set('pay_frequency', 'weekly')
        ->set('next_pay_date', now()->addDays(3)->format('Y-m-d'))
        ->call('save')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->pay_amount)->toBe(123456);
});

test('saved settings display on reload', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_amount' => 250000,
    ]);

    $this->actingAs($user);

    $component = Livewire::test('pages::settings.pay-cycle');

    expect($component->get('pay_amount'))->toBe('2500.00')
        ->and($component->get('pay_frequency'))->toBe('fortnightly');
});

test('validation rejects missing pay amount', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.pay-cycle')
        ->set('pay_amount', '')
        ->set('pay_frequency', 'weekly')
        ->set('next_pay_date', now()->addDays(3)->format('Y-m-d'))
        ->call('save')
        ->assertHasErrors(['pay_amount']);
});

test('validation rejects negative pay amount', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.pay-cycle')
        ->set('pay_amount', '-100.00')
        ->set('pay_frequency', 'weekly')
        ->set('next_pay_date', now()->addDays(3)->format('Y-m-d'))
        ->call('save')
        ->assertHasErrors(['pay_amount']);
});

test('validation rejects invalid pay frequency', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.pay-cycle')
        ->set('pay_amount', '3000.00')
        ->set('pay_frequency', 'daily')
        ->set('next_pay_date', now()->addDays(3)->format('Y-m-d'))
        ->call('save')
        ->assertHasErrors(['pay_frequency']);
});

test('validation rejects missing next pay date', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.pay-cycle')
        ->set('pay_amount', '3000.00')
        ->set('pay_frequency', 'weekly')
        ->set('next_pay_date', '')
        ->call('save')
        ->assertHasErrors(['next_pay_date']);
});

test('validation rejects past next pay date', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.pay-cycle')
        ->set('pay_amount', '3000.00')
        ->set('pay_frequency', 'weekly')
        ->set('next_pay_date', now()->subDay()->format('Y-m-d'))
        ->call('save')
        ->assertHasErrors(['next_pay_date']);
});
