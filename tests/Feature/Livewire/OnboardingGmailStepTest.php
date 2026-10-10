<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Livewire\ConfirmBudgetTags;
use App\Livewire\ConnectBank;
use App\Livewire\GmailConnection;
use App\Livewire\PayeeReview;
use App\Models\GmailCredential;
use App\Models\User;
use Livewire\Livewire;
use Webklex\IMAP\Facades\Client;

test('confirming the pay cycle opens the tags step, then the payee step, before Gmail', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->dispatch('pay-cycle-confirmed')
        ->assertSet('step', ConnectBank::STEP_TAGS)
        ->assertSee('Step 4 of 5')
        ->assertSeeLivewire(ConfirmBudgetTags::class)
        ->dispatch('budget-tags-confirmed')
        ->assertSet('step', ConnectBank::STEP_PAYEES)
        ->assertSee('Step 5 of 5')
        ->assertSeeLivewire(PayeeReview::class)
        ->dispatch('payee-review-finished')
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
        ->dispatch('payee-review-finished')
        ->assertRedirect(route('dashboard'));
});

test('skipping Gmail leaves the dashboard reachable and stores nothing', function () {
    $user = User::factory()->create();
    Client::shouldReceive('make')->never();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->dispatch('payee-review-finished')
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
        ->dispatch('payee-review-finished')
        ->dispatch('gmail-saved')
        ->assertRedirect(route('dashboard'));
});

test('skipping the tags step and the payee step still reaches the optional Gmail step', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->dispatch('pay-cycle-confirmed')
        ->dispatch('budget-tags-confirmed')
        ->dispatch('payee-review-finished')
        ->assertSet('step', ConnectBank::STEP_GMAIL)
        ->assertSee('Optional last step');
});
