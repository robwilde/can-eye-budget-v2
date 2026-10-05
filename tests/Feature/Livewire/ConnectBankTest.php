<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Jobs\SyncRedbarkFeedJob;
use App\Livewire\ConnectBank;
use App\Livewire\RedbarkAccountSetup;
use App\Models\RedbarkFeed;
use App\Models\User;
use App\Services\RedbarkClientFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    Sleep::fake();
    Http::preventStrayRequests();
});

test('the connect-bank page needs authentication and renders step 1 for a new user', function () {
    $this->get(route('connect-bank'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('connect-bank'))
        ->assertOk()
        ->assertSeeLivewire(ConnectBank::class)
        ->assertSee('Step 1 of 2')
        ->assertSee('from the 1st of last month')
        ->assertSee(route('dashboard'), false);
});

test('connecting stores the key encrypted, dispatches a sync and advances to step 2', function () {
    Http::fake(['*/connections' => Http::response(['data' => []])]);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->set('api_key', 'rbk_live_0123456789abcdef')
        ->call('connect')
        ->assertHasNoErrors()
        ->assertSet('step', ConnectBank::STEP_ACCOUNTS)
        ->assertSet('api_key', '')
        ->assertSeeLivewire(RedbarkAccountSetup::class);

    $feed = RedbarkFeed::query()->where('user_id', $user->id)->sole();

    expect($feed->api_key)->toBe('rbk_live_0123456789abcdef')
        ->and(DB::table('redbark_feeds')->where('id', $feed->id)->value('api_key'))
        ->not->toBe('rbk_live_0123456789abcdef');

    Queue::assertPushed(SyncRedbarkFeedJob::class);
});

test('a key Redbark rejects keeps the user on step 1 with an inline error and stores nothing', function (int $status, string $copy) {
    Http::fake(['*/connections' => Http::response(['error' => ['message' => 'nope']], $status)]);

    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->set('api_key', 'rbk_live_0123456789abcdef')
        ->call('connect')
        ->assertHasErrors('api_key')
        ->assertSet('step', ConnectBank::STEP_CONNECT)
        ->assertSee($copy)
        ->assertDontSee('try again');

    expect(RedbarkFeed::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'unauthorized' => [401, 'rejected this API key'],
    'forbidden' => [403, 'plan includes API access'],
]);

test('a rejected key leaves an existing feed untouched', function () {
    Http::fake(['*/connections' => Http::response([], 401)]);
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->for($user)->synced()->pendingSetup()->create(['api_key' => 'rbk_live_old_key_0123456', 'auth_failure_count' => 2]);

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->set('api_key', 'rbk_live_0123456789abcdef')
        ->call('connect');

    $feed->refresh();

    expect($feed->api_key)->toBe('rbk_live_old_key_0123456')
        ->and($feed->auth_failure_count)->toBe(2);
    Queue::assertNothingPushed();
});

test('a Redbark outage shows a retryable message, not the invalid key message', function (int $status) {
    Http::fake(['*/connections' => Http::response([], $status)]);

    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->set('api_key', 'rbk_live_0123456789abcdef')
        ->call('connect')
        ->assertHasErrors('api_key')
        ->assertSet('step', ConnectBank::STEP_CONNECT)
        ->assertSee('try again')
        ->assertDontSee('rejected');

    expect(RedbarkFeed::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([500, 503, 429]);

test('a connection failure shows the retryable message and stores nothing', function () {
    Http::fake(['*/connections' => Http::failedConnection()]);

    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->set('api_key', 'rbk_live_0123456789abcdef')
        ->call('connect')
        ->assertHasErrors('api_key')
        ->assertSet('step', ConnectBank::STEP_CONNECT)
        ->assertSee('try again');

    expect(RedbarkFeed::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('the submitted key is what authenticates the validation call', function () {
    Http::fake(['*/connections' => Http::response(['data' => []])]);
    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->set('api_key', 'rbk_live_0123456789abcdef')
        ->call('connect');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer rbk_live_0123456789abcdef'));
});

test('the validation call is bounded to a single short attempt', function () {
    Http::fake(['*/connections' => Http::response([], 503)]);

    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->set('api_key', 'rbk_live_0123456789abcdef')
        ->call('connect');

    Http::assertSentCount(1);
});

test('the validation call uses the short validation timeout', function () {
    $timeouts = [];
    Http::fake(['*/connections' => function ($request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'] ?? null;

        return Http::response(['data' => []]);
    }]);

    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->set('api_key', 'rbk_live_0123456789abcdef')
        ->call('connect');

    expect($timeouts)->toBe([RedbarkClientFactory::VALIDATION_TIMEOUT_SECONDS]);
});

test('an empty or too-short key is rejected and creates nothing', function (string $key) {
    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->set('api_key', $key)
        ->call('connect')
        ->assertHasErrors('api_key')
        ->assertSet('step', ConnectBank::STEP_CONNECT);

    expect(RedbarkFeed::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['empty' => '', 'too short' => 'short-key']);

test('a user whose feed is fully set up is sent to the dashboard', function () {
    $user = User::factory()->create();
    RedbarkFeed::factory()->for($user)->synced()->create(['pending_account_setup' => false]);

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->assertRedirect(route('dashboard'));
});

test('a user with accounts pending setup opens on step 2', function () {
    $user = User::factory()->create();
    RedbarkFeed::factory()->for($user)->synced()->pendingSetup()->create();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->assertNoRedirect()
        ->assertSet('step', ConnectBank::STEP_ACCOUNTS);
});

test('a feed that has not synced yet opens on step 2 rather than redirecting', function () {
    $user = User::factory()->create();
    RedbarkFeed::factory()->for($user)->create(['pending_account_setup' => false, 'last_synced_at' => null]);

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->assertNoRedirect()
        ->assertSet('step', ConnectBank::STEP_ACCOUNTS);
});

test('skip for now leads to the dashboard on both steps', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->assertSeeHtml('data-test="connect-bank-skip"')
        ->assertSee(route('dashboard'));

    RedbarkFeed::factory()->for($user)->synced()->pendingSetup()->create();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->assertSeeHtml('data-test="connect-bank-skip"')
        ->assertSee(route('dashboard'));
});
