<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\Account;
use App\Models\GmailCredential;
use App\Models\RedbarkFeed;
use App\Models\User;
use Webklex\IMAP\Facades\Client;
use Webklex\PHPIMAP\Client as ImapClient;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;

function onboardingUserAtPayCycle(): User
{
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'Everyday']);
    RedbarkFeed::factory()->for($user)->synced()->create(['pending_account_setup' => false]);

    return $user;
}

function confirmPayCycleInBrowser(User $user): mixed
{
    $accountId = Account::query()->where('user_id', $user->id)->sole()->id;

    return visit('/connect-bank')
        ->assertSee('Step 3 of 5')
        ->select('[data-test="pay-cycle-account"]', (string) $accountId)
        ->fill('[data-test="pay-cycle-amount"]', '2500')
        ->select('[data-test="pay-cycle-frequency"]', 'fortnightly')
        ->fill('[data-test="pay-cycle-next-date"]', now()->addDays(5)->toDateString())
        ->click('[data-test="pay-cycle-confirm"]')
        ->assertSee('Step 4 of 5')
        ->click('[data-test="budget-tags-skip"]')
        ->assertSee('Step 5 of 5')
        ->click('[data-test="payee-review-skip"]')
        ->assertSee('Optional last step');
}

test('skipping the optional Gmail step lands on the dashboard without a connection', function () {
    $user = onboardingUserAtPayCycle();
    $this->actingAs($user);

    confirmPayCycleInBrowser($user)
        ->assertSee('Settings > Providers')
        ->click('[data-test="onboarding-gmail-skip"]')
        ->assertPathIs('/dashboard')
        ->assertSee('Primary account and pay cycle saved.');

    expect(GmailCredential::query()->count())->toBe(0);
});

test('connecting Gmail from onboarding stores the credential and lands on the dashboard', function () {
    $client = Mockery::mock(ImapClient::class);
    $client->shouldReceive('connect')->once()->andReturnSelf();
    $client->shouldReceive('disconnect')->once();
    Client::shouldReceive('make')->once()->andReturn($client);

    $user = onboardingUserAtPayCycle();
    $this->actingAs($user);

    confirmPayCycleInBrowser($user)
        ->fill('input[type="email"]', 'me@gmail.com')
        ->fill('input[type="password"]', 'abcdefghijklmnop')
        ->click('[data-test="save-gmail-button"]')
        ->assertPathIs('/dashboard')
        ->assertSee('Primary account and pay cycle saved.');

    expect(GmailCredential::query()->where('user_id', $user->id)->sole()->username)->toBe('me@gmail.com');
});

test('a rejected Gmail login keeps the step open and skipping still reaches the dashboard', function () {
    $client = Mockery::mock(ImapClient::class);
    $client->shouldReceive('connect')->once()->andThrow(new AuthFailedException('rejected'));
    $client->shouldReceive('disconnect')->zeroOrMoreTimes();
    Client::shouldReceive('make')->once()->andReturn($client);

    $user = onboardingUserAtPayCycle();
    $this->actingAs($user);

    confirmPayCycleInBrowser($user)
        ->fill('input[type="email"]', 'me@gmail.com')
        ->fill('input[type="password"]', 'abcdefghijklmnop')
        ->click('[data-test="save-gmail-button"]')
        ->assertSee('Gmail rejected these details')
        ->assertSee('Optional last step')
        ->click('[data-test="onboarding-gmail-skip"]')
        ->assertPathIs('/dashboard');

    expect(GmailCredential::query()->count())->toBe(0);
});
