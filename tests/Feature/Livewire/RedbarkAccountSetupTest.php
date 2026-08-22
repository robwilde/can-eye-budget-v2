<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountClass;
use App\Enums\ImportSource;
use App\Jobs\SyncRedbarkFeedJob;
use App\Livewire\RedbarkAccountSetup;
use App\Models\Account;
use App\Models\RedbarkAccount;
use App\Models\RedbarkFeed;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
});

/** @return array{0: User, 1: RedbarkFeed, 2: RedbarkAccount} */
function wizardFixture(array $redbarkAccountOverrides = []): array
{
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->synced()->create(['user_id' => $user->id, 'pending_account_setup' => true]);

    $redbarkAccount = RedbarkAccount::factory()->create([
        'redbark_feed_id' => $feed->id,
        'account_id' => null,
        'name' => 'Test Bank - Everyday Account',
        'institution_name' => 'Test Bank',
        'account_number' => '****4321',
        'current_balance' => 123456,
        ...$redbarkAccountOverrides,
    ]);

    return [$user, $feed, $redbarkAccount];
}

test('a user with no feed is sent back to the providers panel', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(RedbarkAccountSetup::class)
        ->assertRedirect(route('providers.edit'));
});

test('accounts needing setup are listed and default to skip', function () {
    [$user, , $redbarkAccount] = wizardFixture();

    Livewire::actingAs($user)
        ->test(RedbarkAccountSetup::class)
        ->assertSee('Test Bank - Everyday Account')
        ->assertSee('$1,234.56')
        ->assertSet("choices.{$redbarkAccount->id}", 'skip');
});

test('choosing a new account type creates and links it', function () {
    [$user, $feed, $redbarkAccount] = wizardFixture();

    Livewire::actingAs($user)
        ->test(RedbarkAccountSetup::class)
        ->set("choices.{$redbarkAccount->id}", 'new:credit-card')
        ->call('save')
        ->assertRedirect(route('accounts'));

    $account = Account::query()->where('user_id', $user->id)->sole();

    expect($account->type)->toBe(AccountClass::CreditCard)
        ->and($account->import_source)->toBe(ImportSource::Redbark)
        ->and($account->name)->toBe('Test Bank - Everyday Account')
        ->and($account->institution)->toBe('Test Bank')
        ->and($account->account_last4)->toBe('4321')
        ->and($account->balance)->toBe(123456)
        ->and($redbarkAccount->fresh()->account_id)->toBe($account->id)
        ->and($feed->fresh()->pending_account_setup)->toBeFalse();

    Queue::assertPushed(SyncRedbarkFeedJob::class);
});

test('linking to an existing account keeps its history and creates nothing', function () {
    [$user, , $redbarkAccount] = wizardFixture();

    $existing = Account::factory()->for($user)->create([
        'name' => 'My Everyday',
        'import_source' => ImportSource::Csv,
    ]);

    Livewire::actingAs($user)
        ->test(RedbarkAccountSetup::class)
        ->assertSee('My Everyday')
        ->set("choices.{$redbarkAccount->id}", "existing:{$existing->id}")
        ->call('save')
        ->assertRedirect(route('accounts'));

    expect(Account::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($existing->fresh()->import_source)->toBe(ImportSource::Redbark)
        ->and($redbarkAccount->fresh()->account_id)->toBe($existing->id);
});

test('an account already carrying a redbark link is refused without aborting the batch', function () {
    [$user, $feed, $redbarkAccount] = wizardFixture();

    $taken = Account::factory()->for($user)->create();

    RedbarkAccount::factory()->create([
        'redbark_feed_id' => $feed->id,
        'account_id' => $taken->id,
    ]);

    Livewire::actingAs($user)
        ->test(RedbarkAccountSetup::class)
        ->set("choices.{$redbarkAccount->id}", "existing:{$taken->id}")
        ->call('save')
        ->assertNoRedirect();

    expect($redbarkAccount->fresh()->account_id)->toBeNull();

    Queue::assertNothingPushed();
});

test('an already linked account is not offered in the existing list', function () {
    [$user, $feed] = wizardFixture();

    $taken = Account::factory()->for($user)->create(['name' => 'Already Connected']);

    RedbarkAccount::factory()->create([
        'redbark_feed_id' => $feed->id,
        'account_id' => $taken->id,
    ]);

    Livewire::actingAs($user)
        ->test(RedbarkAccountSetup::class)
        ->assertDontSee('Already Connected');
});

test('skipping an account hides it from the next visit', function () {
    [$user, $feed, $redbarkAccount] = wizardFixture();

    Livewire::actingAs($user)
        ->test(RedbarkAccountSetup::class)
        ->set("choices.{$redbarkAccount->id}", 'skip')
        ->call('save');

    expect($redbarkAccount->fresh()->ignored)->toBeTrue()
        ->and($feed->fresh()->pending_account_setup)->toBeFalse();

    // A skip links nothing, so there is nothing new to sync.
    Queue::assertNothingPushed();

    Livewire::actingAs($user)
        ->test(RedbarkAccountSetup::class)
        ->assertDontSee('Test Bank - Everyday Account')
        ->assertSee('Nothing left to set up');
});

test('another user\'s redbark account cannot be claimed', function () {
    [$user] = wizardFixture();

    $foreign = RedbarkAccount::factory()->create();

    Livewire::actingAs($user)
        ->test(RedbarkAccountSetup::class)
        ->set("choices.{$foreign->id}", 'new:savings')
        ->call('save')
        ->assertForbidden();

    expect($foreign->fresh()->account_id)->toBeNull();
});

test('another user\'s app account cannot be linked', function () {
    [$user, , $redbarkAccount] = wizardFixture();

    $foreignAccount = Account::factory()->create();

    Livewire::actingAs($user)
        ->test(RedbarkAccountSetup::class)
        ->set("choices.{$redbarkAccount->id}", "existing:{$foreignAccount->id}")
        ->call('save')
        ->assertForbidden();

    expect($redbarkAccount->fresh()->account_id)->toBeNull();
});

test('mount queues a sync for a never-synced feed instead of running it inline', function () {
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(RedbarkAccountSetup::class)
        ->assertSee('Fetching your accounts from Redbark')
        ->assertSeeHtml('wire:poll.3s');

    Queue::assertPushed(SyncRedbarkFeedJob::class, 1);

    expect($feed->fresh()->last_synced_at)->toBeNull();
});

test('resubmitting after a partial failure does not re-apply already-resolved choices', function () {
    [$user, $feed, $accountA] = wizardFixture();

    $accountB = RedbarkAccount::factory()->create([
        'redbark_feed_id' => $feed->id,
        'account_id' => null,
    ]);

    $taken = Account::factory()->for($user)->create();

    RedbarkAccount::factory()->create([
        'redbark_feed_id' => $feed->id,
        'account_id' => $taken->id,
    ]);

    $component = Livewire::actingAs($user)
        ->test(RedbarkAccountSetup::class)
        ->set("choices.{$accountA->id}", 'new:credit-card')
        ->set("choices.{$accountB->id}", "existing:{$taken->id}")
        ->call('save')
        ->assertNoRedirect();

    $linkedAccountId = $accountA->fresh()->account_id;

    expect($linkedAccountId)->not->toBeNull()
        ->and($accountB->fresh()->account_id)->toBeNull();

    // Resubmitting without changing anything must not re-create an account for the
    // row that already succeeded, nor re-throw the stale 'already connected' error.
    $component->call('save')->assertNoRedirect();

    expect(Account::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and($accountA->fresh()->account_id)->toBe($linkedAccountId)
        ->and($accountB->fresh()->account_id)->toBeNull();
});

test('a partially-failed batch still dispatches sync for the accounts that succeeded', function () {
    [$user, $feed, $accountA] = wizardFixture();

    $accountB = RedbarkAccount::factory()->create([
        'redbark_feed_id' => $feed->id,
        'account_id' => null,
    ]);

    $taken = Account::factory()->for($user)->create();

    RedbarkAccount::factory()->create([
        'redbark_feed_id' => $feed->id,
        'account_id' => $taken->id,
    ]);

    Livewire::actingAs($user)
        ->test(RedbarkAccountSetup::class)
        ->set("choices.{$accountA->id}", 'new:credit-card')
        ->set("choices.{$accountB->id}", "existing:{$taken->id}")
        ->call('save')
        ->assertNoRedirect();

    expect($accountA->fresh()->account_id)->not->toBeNull();

    Queue::assertPushed(SyncRedbarkFeedJob::class, 1);
});

test('choosing a new account type outside the offered list is rejected', function () {
    [$user, , $redbarkAccount] = wizardFixture();

    Livewire::actingAs($user)
        ->test(RedbarkAccountSetup::class)
        ->set("choices.{$redbarkAccount->id}", 'new:investment')
        ->call('save')
        ->assertNoRedirect();

    expect($redbarkAccount->fresh()->account_id)->toBeNull();

    Queue::assertNothingPushed();
});
