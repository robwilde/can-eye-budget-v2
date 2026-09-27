<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Jobs\SyncRedbarkFeedJob;
use App\Livewire\ConnectBank;
use App\Livewire\RedbarkAccountSetup;
use App\Models\RedbarkFeed;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
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
