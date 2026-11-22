<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Enums\RedbarkFeedStatus;
use App\Enums\RedbarkTransport;
use App\Enums\RefreshStatus;
use App\Jobs\SyncRedbarkFeedJob;
use App\Livewire\ConnectBank;
use App\Livewire\RedbarkAccountSetup;
use App\Models\Account;
use App\Models\RedbarkAccount;
use App\Models\RedbarkFeed;
use App\Models\RedbarkSyncLog;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
});

function perTransportSimpleFinFake(): string
{
    Http::fake(['*/simplefin/claim/*' => Http::response('https://sfuser:sfpass@api.redbark.com/simplefin', 200, ['Content-Type' => 'text/plain'])]);

    return base64_encode('https://api.redbark.com/simplefin/claim/sfin_setup_per_transport');
}

function perTransportFeed(User $user, RedbarkTransport $transport): RedbarkFeed
{
    return RedbarkFeed::factory()->for($user)->synced()->create($transport === RedbarkTransport::Rest
        ? ['transport' => $transport]
        : ['transport' => $transport, 'api_key' => null, 'simplefin_access_url' => 'https://sfuser:sfpass@api.redbark.com/simplefin']);
}

it('allows one feed per transport for a user', function () {
    $user = User::factory()->create();
    perTransportFeed($user, RedbarkTransport::Rest);

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->set('setup_token', perTransportSimpleFinFake())
        ->call('connectSimpleFin')
        ->assertHasNoErrors();

    expect(RedbarkFeed::query()->where('user_id', $user->id)->pluck('transport')->all())->toEqualCanonicalizing([RedbarkTransport::Rest, RedbarkTransport::SimpleFin]);

    Livewire::actingAs($user)
        ->test('pages::settings.providers')
        ->assertSeeHtml('data-test="redbark-feed-rest"')
        ->assertSeeHtml('data-test="redbark-feed-simplefin"');
});

it('connects SimpleFIN from the providers page beside a REST feed', function () {
    $user = User::factory()->create();
    perTransportFeed($user, RedbarkTransport::Rest);

    Livewire::actingAs($user)
        ->test('pages::settings.providers')
        ->set('setup_token', perTransportSimpleFinFake())
        ->call('connectSimpleFin')
        ->assertHasNoErrors()
        ->assertSet('setup_token', '')
        ->assertSeeHtml('data-test="redbark-feed-simplefin"');

    Queue::assertPushed(SyncRedbarkFeedJob::class, 1);
    expect(RedbarkFeed::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('refuses a second feed on the same transport', function () {
    $user = User::factory()->create();
    perTransportFeed($user, RedbarkTransport::Rest);
    Http::fake();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->set('api_key', 'rbk_live_0123456789abcdef')
        ->call('connect')
        ->assertHasErrors('api_key');

    Http::assertNothingSent();
    expect(RedbarkFeed::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(fn () => RedbarkFeed::factory()->for($user)->create(['transport' => RedbarkTransport::Rest]))->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a second SimpleFIN feed before the one-shot token is claimed', function () {
    $user = User::factory()->create();
    perTransportFeed($user, RedbarkTransport::SimpleFin);
    $token = perTransportSimpleFinFake();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->set('setup_token', $token)
        ->call('connectSimpleFin')
        ->assertHasErrors('setup_token');

    Http::assertNothingSent();
});

it('disconnects one feed without affecting the other', function () {
    $user = User::factory()->create();
    $simpleFin = perTransportFeed($user, RedbarkTransport::SimpleFin);
    $rest = perTransportFeed($user, RedbarkTransport::Rest);
    $restAccount = Account::factory()->for($user)->create(['import_source' => ImportSource::Redbark]);
    $simpleFinAccount = Account::factory()->for($user)->create(['import_source' => ImportSource::Redbark]);
    RedbarkAccount::factory()->for($rest, 'feed')->linked($restAccount)->create();
    $kept = RedbarkAccount::factory()->for($simpleFin, 'feed')->linked($simpleFinAccount)->create();
    Transaction::factory()->for($user)->create(['account_id' => $simpleFinAccount->id]);

    Livewire::actingAs($user)->test('pages::settings.providers')->call('disconnect', $rest->id);

    expect(RedbarkFeed::query()->where('user_id', $user->id)->pluck('id')->all())->toBe([$simpleFin->id])
        ->and(RedbarkAccount::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and($simpleFinAccount->fresh()->import_source)->toBe(ImportSource::Redbark)
        ->and($restAccount->fresh()->import_source)->toBe(ImportSource::Manual)
        ->and(Transaction::query()->where('account_id', $simpleFinAccount->id)->count())->toBe(1);
});

it('lists only the user\'s own feeds', function () {
    $user = User::factory()->create();
    perTransportFeed($user, RedbarkTransport::Rest);
    perTransportFeed(User::factory()->create(), RedbarkTransport::SimpleFin);

    Livewire::actingAs($user)
        ->test('pages::settings.providers')
        ->assertSeeHtml('data-test="redbark-feed-rest"')
        ->assertDontSeeHtml('data-test="redbark-feed-simplefin"');
});

it('dispatches a sync job per feed', function () {
    $user = User::factory()->create();
    perTransportFeed($user, RedbarkTransport::Rest);
    perTransportFeed($user, RedbarkTransport::SimpleFin);
    RedbarkFeed::factory()->requiresUpdate()->create();

    Artisan::call('app:sync-redbark-feeds');

    Queue::assertPushed(SyncRedbarkFeedJob::class, 2);
});

it('links an app account to at most one redbark account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $rest = RedbarkAccount::factory()->for(perTransportFeed($user, RedbarkTransport::Rest), 'feed')->linked($account)->create();
    $other = RedbarkAccount::factory()->for(perTransportFeed($user, RedbarkTransport::SimpleFin), 'feed')->create(['account_id' => null]);

    expect(fn () => $other->update(['account_id' => $account->id]))->toThrow(UniqueConstraintViolationException::class)
        ->and($rest->fresh()->account_id)->toBe($account->id);
});

it('sets up the accounts of every feed in one wizard', function () {
    $user = User::factory()->create();
    $rest = perTransportFeed($user, RedbarkTransport::Rest);
    $simpleFin = perTransportFeed($user, RedbarkTransport::SimpleFin);
    $rest->update(['pending_account_setup' => true]);
    $simpleFin->update(['pending_account_setup' => true]);
    RedbarkAccount::factory()->for($rest, 'feed')->create(['account_id' => null, 'name' => 'Rest Everyday', 'account_type' => 'transaction']);
    RedbarkAccount::factory()->for($simpleFin, 'feed')->create(['account_id' => null, 'name' => 'Sfin Saver', 'account_type' => 'savings']);

    Livewire::actingAs($user)
        ->test(RedbarkAccountSetup::class)
        ->assertSee('Rest Everyday')
        ->assertSee('Sfin Saver')
        ->call('save');

    expect(RedbarkAccount::query()->whereNull('account_id')->count())->toBe(0)
        ->and($rest->fresh()->pending_account_setup)->toBeFalse()
        ->and($simpleFin->fresh()->pending_account_setup)->toBeFalse();

    Queue::assertPushed(SyncRedbarkFeedJob::class, 2);
});

it('does not set up the accounts of another user\'s feeds', function () {
    $user = User::factory()->create();
    perTransportFeed($user, RedbarkTransport::Rest);
    $foreign = perTransportFeed(User::factory()->create(), RedbarkTransport::SimpleFin);
    RedbarkAccount::factory()->for($foreign, 'feed')->create(['account_id' => null, 'name' => 'Foreign Saver']);

    Livewire::actingAs($user)->test(RedbarkAccountSetup::class)->assertDontSee('Foreign Saver');
});

it('keeps onboarding on the accounts step while any feed still has accounts to set up', function () {
    $user = User::factory()->create();
    perTransportFeed($user, RedbarkTransport::Rest);
    perTransportFeed($user, RedbarkTransport::SimpleFin)->update(['pending_account_setup' => true]);

    Livewire::actingAs($user)->test(ConnectBank::class)->assertSet('step', ConnectBank::STEP_ACCOUNTS);
});

it('keeps the feed status when a key is saved on the other transport', function () {
    $user = User::factory()->create();
    $simpleFin = perTransportFeed($user, RedbarkTransport::SimpleFin);
    $simpleFin->update(['status' => RedbarkFeedStatus::RequiresUpdate]);

    Livewire::actingAs($user)
        ->test('pages::settings.providers')
        ->set('api_key', 'rbk_live_0123456789abcdef')
        ->call('save')
        ->assertHasNoErrors();

    expect($simpleFin->fresh()->status)->toBe(RedbarkFeedStatus::RequiresUpdate)
        ->and(RedbarkFeed::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('syncs only the feed whose Sync now was pressed', function () {
    $user = User::factory()->create();
    $rest = perTransportFeed($user, RedbarkTransport::Rest);
    $simpleFin = perTransportFeed($user, RedbarkTransport::SimpleFin);
    RedbarkSyncLog::factory()->create(['user_id' => $user->id, 'redbark_feed_id' => $rest->id, 'status' => RefreshStatus::Pending]);

    Livewire::actingAs($user)->test('pages::settings.providers')->call('syncNow', $simpleFin->id)->call('syncNow', $rest->id);

    Queue::assertPushed(SyncRedbarkFeedJob::class, 1);
    Queue::assertPushed(SyncRedbarkFeedJob::class, fn (SyncRedbarkFeedJob $job): bool => $job->feed->is($simpleFin));
});

it('disconnects the feed that was asked for, not the user\'s first one', function () {
    $user = User::factory()->create();
    $first = perTransportFeed($user, RedbarkTransport::Rest);
    $second = perTransportFeed($user, RedbarkTransport::SimpleFin);

    Livewire::actingAs($user)->test('pages::settings.providers')->call('disconnect', $second->id);

    expect(RedbarkFeed::query()->where('user_id', $user->id)->pluck('id')->all())->toBe([$first->id]);
});

it('replaces a SimpleFIN token and clears only that feed\'s requires-update state', function () {
    $user = User::factory()->create();
    $rest = perTransportFeed($user, RedbarkTransport::Rest);
    $simpleFin = perTransportFeed($user, RedbarkTransport::SimpleFin);
    $rest->update(['status' => RedbarkFeedStatus::RequiresUpdate, 'auth_failure_count' => 3]);
    $simpleFin->update(['status' => RedbarkFeedStatus::RequiresUpdate, 'auth_failure_count' => 3]);

    Livewire::actingAs($user)
        ->test('pages::settings.providers')
        ->set('setup_token', perTransportSimpleFinFake())
        ->call('connectSimpleFin')
        ->assertHasNoErrors();

    expect($simpleFin->fresh()->status)->toBe(RedbarkFeedStatus::Good)
        ->and($simpleFin->fresh()->auth_failure_count)->toBe(0)
        ->and($rest->fresh()->status)->toBe(RedbarkFeedStatus::RequiresUpdate)
        ->and(RedbarkFeed::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('syncs and polls a feed that was added after the first one finished setup', function () {
    $user = User::factory()->create();
    perTransportFeed($user, RedbarkTransport::Rest);
    $simpleFin = perTransportFeed($user, RedbarkTransport::SimpleFin);
    $simpleFin->update(['last_synced_at' => null]);

    Livewire::actingAs($user)->test(RedbarkAccountSetup::class)->assertSet('isSyncing', true);

    Queue::assertPushed(SyncRedbarkFeedJob::class, fn (SyncRedbarkFeedJob $job): bool => $job->feed->is($simpleFin));
});

it('shows why the second feed found nothing', function () {
    $user = User::factory()->create();
    perTransportFeed($user, RedbarkTransport::Rest);
    $simpleFin = perTransportFeed($user, RedbarkTransport::SimpleFin);
    RedbarkAccount::factory()->for(RedbarkFeed::query()->where('user_id', $user->id)->where('transport', RedbarkTransport::Rest)->sole(), 'feed')->linked(Account::factory()->for($user)->create())->create();
    RedbarkSyncLog::factory()->create(['user_id' => $user->id, 'redbark_feed_id' => $simpleFin->id, 'status' => RefreshStatus::Failed, 'errors' => [['context' => 'accounts', 'message' => 'Bridge unavailable']]]);

    Livewire::actingAs($user)->test(RedbarkAccountSetup::class)->assertSee('Bridge unavailable');
});

it('does not offer to create a second account for a bank account another feed already reports', function () {
    $user = User::factory()->create();
    $rest = perTransportFeed($user, RedbarkTransport::Rest);
    $simpleFin = perTransportFeed($user, RedbarkTransport::SimpleFin);
    $linked = Account::factory()->for($user)->create();
    RedbarkAccount::factory()->for($rest, 'feed')->linked($linked)->create(['redbark_account_id' => 'same-bank-account']);
    $duplicate = RedbarkAccount::factory()->for($simpleFin, 'feed')->create(['account_id' => null, 'redbark_account_id' => 'same-bank-account', 'account_type' => 'transaction']);

    Livewire::actingAs($user)->test(RedbarkAccountSetup::class)->assertSet('choices.'.$duplicate->id, 'skip');
});
