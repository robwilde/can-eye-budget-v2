<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Enums\RedbarkFeedStatus;
use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\Account;
use App\Models\RedbarkAccount;
use App\Models\RedbarkFeed;
use App\Models\RedbarkSyncLog;
use App\Models\User;
use App\Services\RedbarkClientFactory;
use App\Services\RedbarkTransactionMatcher;
use App\Services\TransactionIngestor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
});

test('the providers page is displayed and needs authentication', function () {
    $this->get(route('providers.edit'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create());

    $this->get(route('providers.edit'))->assertOk()->assertSee('Providers');
});

test('saving a key stores it encrypted and kicks off a sync', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.providers')
        ->set('api_key', 'rbk_live_abc123def456')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('redbark-saved')
        ->assertSet('api_key', '');

    $feed = RedbarkFeed::query()->where('user_id', $user->id)->firstOrFail();
    $stored = DB::table('redbark_feeds')->where('id', $feed->id)->value('api_key');

    expect($feed->api_key)->toBe('rbk_live_abc123def456')
        ->and($feed->status)->toBe(RedbarkFeedStatus::Good)
        ->and($stored)->not->toBe('rbk_live_abc123def456');

    Queue::assertPushed(SyncRedbarkFeedJob::class);
});

test('saving opens the sync log immediately so the panel polls without a refresh', function () {
    $user = User::factory()->create();

    // The job is queued, so nothing but the dispatch itself can have opened this log.
    Livewire::actingAs($user)
        ->test('pages::settings.providers')
        ->set('api_key', 'rbk_live_abc123def456')
        ->call('save')
        ->assertSet('isSyncing', true)
        ->assertSeeHtml('wire:poll.3s');

    $log = RedbarkSyncLog::query()->where('user_id', $user->id)->sole();

    expect($log->status)->toBe(RefreshStatus::Pending)
        ->and($log->trigger)->toBe(RefreshTrigger::Manual);
});

test('the job reuses the log opened at dispatch instead of starting a second one', function () {
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->create(['user_id' => $user->id]);

    Http::fake([
        '*/connections*' => Http::response(['data' => []]),
        '*/accounts*' => Http::response(['data' => [], 'pagination' => ['hasMore' => false]]),
        '*/transactions*' => Http::response(['data' => [], 'pagination' => ['hasMore' => false]]),
        '*/balances*' => Http::response(['data' => []]),
    ]);

    SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Manual);

    expect(RedbarkSyncLog::query()->count())->toBe(1);

    (new SyncRedbarkFeedJob($feed, RefreshTrigger::Manual))->handle(
        app(RedbarkClientFactory::class),
        app(TransactionIngestor::class),
        app(RedbarkTransactionMatcher::class),
    );

    $log = RedbarkSyncLog::query()->sole();

    expect($log->status)->toBe(RefreshStatus::Success);
});

test('a short key is rejected', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.providers')
        ->set('api_key', 'too-short')
        ->call('save')
        ->assertHasErrors(['api_key' => 'min']);

    expect(RedbarkFeed::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

test('rotating the key clears a requires-update state', function () {
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->requiresUpdate()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::settings.providers')
        ->set('api_key', 'rbk_live_rotated_key_1234')
        ->call('save');

    $feed->refresh();

    expect($feed->status)->toBe(RedbarkFeedStatus::Good)
        ->and($feed->api_key)->toBe('rbk_live_rotated_key_1234')
        ->and(RedbarkFeed::query()->count())->toBe(1);
});

test('sync now dispatches a job', function () {
    $user = User::factory()->create();
    RedbarkFeed::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::settings.providers')
        ->call('syncNow');

    Queue::assertPushed(SyncRedbarkFeedJob::class, 1);
});

test('sync now is a no-op while a sync is still open', function () {
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->create(['user_id' => $user->id]);

    RedbarkSyncLog::factory()->create([
        'user_id' => $user->id,
        'redbark_feed_id' => $feed->id,
        'status' => RefreshStatus::Pending,
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.providers')
        ->assertSet('isSyncing', true)
        ->call('syncNow');

    Queue::assertNothingPushed();
});

test('a pending log older than 15 minutes no longer blocks a manual sync', function () {
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->create(['user_id' => $user->id]);

    RedbarkSyncLog::factory()->create([
        'user_id' => $user->id,
        'redbark_feed_id' => $feed->id,
        'status' => RefreshStatus::Pending,
        'created_at' => now()->subMinutes(20),
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.providers')
        ->assertSet('isSyncing', false)
        ->call('syncNow');

    Queue::assertPushed(SyncRedbarkFeedJob::class, 1);
});

test('the panel offers account setup only while accounts are waiting', function () {
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::settings.providers')
        ->assertDontSee('Set up accounts');

    $feed->update(['pending_account_setup' => true]);

    Livewire::actingAs($user)
        ->test('pages::settings.providers')
        ->assertSee('Set up accounts');
});

test('sync log errors are shown to the user', function () {
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->create(['user_id' => $user->id]);

    RedbarkSyncLog::factory()->create([
        'user_id' => $user->id,
        'redbark_feed_id' => $feed->id,
        'status' => RefreshStatus::Failed,
        'errors' => [['context' => 'balances', 'message' => 'Rate limit exceeded']],
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.providers')
        ->assertSee('balances')
        ->assertSee('Rate limit exceeded');
});

test('disconnecting deletes the feed and hands the accounts back as manual', function () {
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->for($user)->create(['import_source' => ImportSource::Redbark]);

    RedbarkAccount::factory()->create([
        'redbark_feed_id' => $feed->id,
        'account_id' => $account->id,
    ]);

    RedbarkSyncLog::factory()->create(['user_id' => $user->id, 'redbark_feed_id' => $feed->id]);

    Livewire::actingAs($user)
        ->test('pages::settings.providers')
        ->call('disconnect');

    expect(RedbarkFeed::query()->count())->toBe(0)
        ->and(RedbarkAccount::query()->count())->toBe(0)
        ->and(RedbarkSyncLog::query()->count())->toBe(0)
        ->and($account->fresh()->import_source)->toBe(ImportSource::Manual)
        ->and($account->fresh()->acceptsCsvImports())->toBeTrue();
});

test('one user cannot see another user\'s feed', function () {
    $user = User::factory()->create();
    RedbarkFeed::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.providers')
        ->call('syncNow');

    Queue::assertNothingPushed();
});
