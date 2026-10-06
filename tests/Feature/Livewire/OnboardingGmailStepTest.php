<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Livewire\ConnectBank;
use App\Livewire\GmailConnection;
use App\Models\GmailCredential;
use App\Models\User;
use Livewire\Livewire;
use Webklex\IMAP\Facades\Client;

test('confirming the pay cycle offers Gmail as an optional last step', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->dispatch('pay-cycle-confirmed')
        ->assertNoRedirect()
        ->assertSet('step', ConnectBank::STEP_GMAIL)
        ->assertSee('Optional last step')
        ->assertSeeLivewire(GmailConnection::class)
        ->assertSeeHtml('data-test="onboarding-gmail-skip"')
        ->assertSee('Settings > Providers');
});

test('a user who already connected Gmail goes straight to the dashboard', function () {
    $user = User::factory()->create();
    GmailCredential::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->dispatch('pay-cycle-confirmed')
        ->assertRedirect(route('dashboard'));
});

test('skipping Gmail leaves the dashboard reachable and stores nothing', function () {
    $user = User::factory()->create();
    Client::shouldReceive('make')->never();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->dispatch('pay-cycle-confirmed')
        ->call('finish')
        ->assertRedirect(route('dashboard'));

    expect(GmailCredential::query()->count())->toBe(0);
});

test('finishing flashes the pay cycle success message for the dashboard', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->call('finish');

    expect(session()->get('status'))->toBe('Primary account and pay cycle saved.');
});

test('the Gmail saved event from the embedded form finishes onboarding', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->dispatch('pay-cycle-confirmed')
        ->dispatch('gmail-saved')
        ->assertRedirect(route('dashboard'));
});
