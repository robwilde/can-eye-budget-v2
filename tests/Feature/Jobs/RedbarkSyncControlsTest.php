<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Enums\RedbarkFeedStatus;
use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Jobs\RunTransactionAnalysisJob;
use App\Jobs\SyncRedbarkFeedJob;
use App\Listeners\SyncFeedsOnLogin;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\RedbarkAccount;
use App\Models\RedbarkFeed;
use App\Models\RedbarkSyncLog;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
});

it('does not start a second sync while one is open', function () {
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->for($user)->create();
    RedbarkSyncLog::factory()->create(['user_id' => $user->id, 'redbark_feed_id' => $feed->id]);

    Livewire::actingAs($user)->test('pages::settings.providers')->call('syncNow', $feed->id);
    SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Scheduled);

    Queue::assertNothingPushed();
    expect(RedbarkSyncLog::query()->count())->toBe(1);
});

it('widens the open sync window instead of dropping the request', function () {
    $feed = RedbarkFeed::factory()->create();
    SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Scheduled, CarbonImmutable::parse('2026-03-01'));

    SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Manual, CarbonImmutable::parse('2026-02-01'));
    SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Manual, CarbonImmutable::parse('2026-04-01'));

    expect(RedbarkSyncLog::query()->sole()->since_date?->toDateString())->toBe('2026-02-01');
    Queue::assertPushed(SyncRedbarkFeedJob::class, 1);
});

it('joins an open nightly run when the six-hourly run lands close to it', function () {
    RedbarkFeed::factory()->count(2)->create();

    $this->artisan('app:sync-redbark-feeds')->assertSuccessful();
    $this->artisan('app:sync-redbark-feeds')->assertSuccessful();

    Queue::assertPushed(SyncRedbarkFeedJob::class, 2);
    expect(RedbarkSyncLog::query()->count())->toBe(2);
});

it('cancels a running sync and records an audit event', function () {
    Queue::fake([RunTransactionAnalysisJob::class]);
    Http::preventStrayRequests();
    [$feed] = linkedRedbarkFeed();
    $user = $feed->user;
    $log = RedbarkSyncLog::factory()->create(['user_id' => $user->id, 'redbark_feed_id' => $feed->id]);

    Livewire::actingAs($user)->test('pages::settings.providers')->call('cancelSync', $feed->id);

    expect($log->fresh()->cancelled_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', 'livewire.pages::settings.providers.cancelSync')->where('user_id', $user->id)->exists())->toBeTrue();

    fakeRedbark(accounts: [redbarkUpstreamAccount()]);
    new SyncRedbarkFeedJob($feed)->handle(app(App\Services\RedbarkClientFactory::class), app(App\Services\TransactionIngestor::class), app(App\Services\RedbarkTransactionMatcher::class));

    expect($log->fresh()->status)->toBe(RefreshStatus::Stale)
        ->and(Transaction::query()->count())->toBe(0);
});

it('syncs once a day on login only when the preference is on', function () {
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->for($user)->create();
    $other = RedbarkFeed::factory()->for($user)->create(['transport' => App\Enums\RedbarkTransport::SimpleFin, 'api_key' => null, 'simplefin_access_url' => 'https://u:p@api.redbark.com/simplefin']);

    new SyncFeedsOnLogin()->handle(new Login('web', $user, false));
    Queue::assertNothingPushed();

    $user->updatePreferences(['autoSyncOnLogin' => true]);
    new SyncFeedsOnLogin()->handle(new Login('web', $user->fresh(), false));
    RedbarkSyncLog::query()->update(['status' => RefreshStatus::Success]);
    new SyncFeedsOnLogin()->handle(new Login('web', $user->fresh(), false));

    Queue::assertPushed(SyncRedbarkFeedJob::class, 2);
    expect(RedbarkSyncLog::query()->where('trigger', RefreshTrigger::Login)->count())->toBe(2)
        ->and($feed->status)->toBe(RedbarkFeedStatus::Good)
        ->and($other->exists)->toBeTrue();
});

it('flags a linked account that vanished upstream', function () {
    Queue::fake([RunTransactionAnalysisJob::class]);
    [$feed, $redbarkAccount] = linkedRedbarkFeed();
    fakeRedbark(accounts: [redbarkUpstreamAccount(['id' => 'some_other_account'])]);

    new SyncRedbarkFeedJob($feed)->handle(app(App\Services\RedbarkClientFactory::class), app(App\Services\TransactionIngestor::class), app(App\Services\RedbarkTransactionMatcher::class));

    expect($redbarkAccount->fresh()->missing_upstream_at)->not->toBeNull();
});

it('deletes a vanished link and keeps the account and its transactions', function () {
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->for($user)->create();
    $account = Account::factory()->for($user)->create(['import_source' => ImportSource::Redbark]);
    $link = RedbarkAccount::factory()->for($feed, 'feed')->linked($account)->create(['missing_upstream_at' => now()]);
    Transaction::factory()->count(100)->for($user)->create(['account_id' => $account->id]);

    Livewire::actingAs($user)->test('pages::settings.providers')->call('manageVanishedLink', $link->id)->call('deleteLink');

    expect(RedbarkAccount::query()->whereKey($link->id)->exists())->toBeFalse()
        ->and($account->fresh()->import_source)->toBe(ImportSource::Manual)
        ->and(Transaction::query()->where('account_id', $account->id)->count())->toBe(100)
        ->and(AuditEvent::query()->where('action', 'livewire.pages::settings.providers.deleteLink')->exists())->toBeTrue();
});

it('does not pick up a cancelled run for a later request', function () {
    $feed = RedbarkFeed::factory()->create();
    SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Scheduled);
    RedbarkSyncLog::query()->update(['cancelled_at' => now()]);

    SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Manual);

    expect(RedbarkSyncLog::query()->count())->toBe(2);
});

it('does not carry the old history or balance onto the account a link is moved to', function () {
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->for($user)->create();
    $account = Account::factory()->for($user)->create(['import_source' => ImportSource::Redbark]);
    $target = Account::factory()->for($user)->create();
    $link = RedbarkAccount::factory()->for($feed, 'feed')->linked($account)->create(['missing_upstream_at' => now(), 'current_balance' => 12345, 'raw_transactions_payload' => [['id' => 'x']]]);

    Livewire::actingAs($user)->test('pages::settings.providers')->call('manageVanishedLink', $link->id)->call('moveLink', '');
    Livewire::actingAs($user)->test('pages::settings.providers')->call('manageVanishedLink', $link->id)->call('moveLink', (string) $target->id);

    expect($link->fresh()->raw_transactions_payload)->toBeNull()
        ->and($link->fresh()->current_balance)->toBeNull();
});

it('refuses to move a link onto another user\'s account', function () {
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->for($user)->create();
    $account = Account::factory()->for($user)->create(['import_source' => ImportSource::Redbark]);
    $link = RedbarkAccount::factory()->for($feed, 'feed')->linked($account)->create(['missing_upstream_at' => now()]);
    $foreign = Account::factory()->for(User::factory()->create())->create();

    Livewire::actingAs($user)->test('pages::settings.providers')->call('manageVanishedLink', $link->id)->call('moveLink', $foreign->id)->assertHasErrors('moveTarget');

    expect($link->fresh()->account_id)->toBe($account->id)
        ->and($foreign->fresh()->import_source)->not->toBe(ImportSource::Redbark);
});

it('moves a vanished link onto one of the user\'s own accounts', function () {
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->for($user)->create();
    $account = Account::factory()->for($user)->create(['import_source' => ImportSource::Redbark]);
    $target = Account::factory()->for($user)->create();
    $link = RedbarkAccount::factory()->for($feed, 'feed')->linked($account)->create(['missing_upstream_at' => now()]);

    Livewire::actingAs($user)->test('pages::settings.providers')->call('manageVanishedLink', $link->id)->call('moveLink', $target->id);

    expect($link->fresh()->account_id)->toBe($target->id)
        ->and($account->fresh()->import_source)->toBe(ImportSource::Manual);
});

it('schedules the nightly sync at the configured time beside the six-hourly one', function () {
    $this->artisan('schedule:list')->assertSuccessful();

    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'app:sync-redbark-feeds'));

    expect($events->pluck('expression')->all())->toContain('0 */6 * * *')->toContain('22 2 * * *')
        ->and($events->firstWhere('expression', '22 2 * * *')->timezone)->toBe('UTC');
});
