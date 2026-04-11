<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\BasiqServiceContract;
use App\DTOs\BasiqUser;
use App\Jobs\RefreshBasiqConnectionsJob;
use App\Livewire\ConnectBank;
use App\Models\Account;
use App\Models\BasiqRefreshLog;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;

beforeEach(function () {
    config(['services.basiq.consent_url' => 'https://consent.basiq.io']);
});

function fakeBasiqService(?callable $configure = null): MockInterface
{
    $mock = Mockery::mock(BasiqServiceContract::class);

    $mock->shouldReceive('createUser')
        ->andReturn(new BasiqUser(id: 'new-basiq-id', email: 'test@example.com'))
        ->byDefault();

    $mock->shouldReceive('clientToken')
        ->andReturn('fake-token')
        ->byDefault();

    if ($configure) {
        $configure($mock);
    }

    app()->instance(BasiqServiceContract::class, $mock);

    return $mock;
}

test('new user without basiq_user_id calls createUser and stores basiq id', function () {
    $user = User::factory()->create(['basiq_user_id' => null]);

    fakeBasiqService(function (MockInterface $mock) use ($user) {
        $mock->shouldReceive('createUser')
            ->once()
            ->with($user->email)
            ->andReturn(new BasiqUser(id: 'new-basiq-id', email: $user->email));
    });

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->call('connect')
        ->assertRedirect();

    expect($user->fresh()->basiq_user_id)->toBe('new-basiq-id');
});

test('existing user with basiq_user_id skips createUser', function () {
    $user = User::factory()->withBasiq()->create();

    fakeBasiqService(function (MockInterface $mock) {
        $mock->shouldNotReceive('createUser');
    });

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->call('connect')
        ->assertRedirect();
});

test('state parameter is stored in session with 40 characters', function () {
    $user = User::factory()->withBasiq()->create();

    fakeBasiqService();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->call('connect');

    expect(session('basiq_consent_state'))
        ->toBeString()
        ->toHaveLength(40);
});

test('consent url includes correct token action and state query params', function () {
    $user = User::factory()->withBasiq()->create();

    fakeBasiqService();

    $response = Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->call('connect');

    $redirectUrl = $response->effects['redirect'];
    $parsed = parse_url($redirectUrl);
    parse_str($parsed['query'], $query);

    expect($parsed['scheme'])
        ->toBe('https')
        ->and($parsed['host'])->toBe('consent.basiq.io')
        ->and($parsed['path'])->toBe('/home')
        ->and($query['token'])->toBe('fake-token')
        ->and($query['action'])->toBe('manage')
        ->and($query['state'])->toBe(session('basiq_consent_state'));
});

test('action connect produces correct consent url action param for new user', function () {
    $user = User::factory()->create(['basiq_user_id' => null]);

    fakeBasiqService();

    $response = Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->call('connect');

    $redirectUrl = $response->effects['redirect'];
    parse_str(parse_url($redirectUrl, PHP_URL_QUERY), $query);

    expect($query['action'])->toBe('connect');
});

test('connect bank route requires authentication', function () {
    $this
        ->get(route('connect-bank'))
        ->assertRedirect(route('login'));
});

test('connected user sees connection status section', function () {
    $user = User::factory()->withBasiq()->create(['last_synced_at' => now()]);

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->assertSee('Connection Status')
        ->assertSee('Sync Summary');
});

test('unconnected user sees only the connect button', function () {
    $user = User::factory()->create(['basiq_user_id' => null]);

    fakeBasiqService();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->assertSee('No bank connected')
        ->assertDontSee('Connection Status');
});

test('action defaults to manage for connected users', function () {
    $user = User::factory()->withBasiq()->create();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->assertSet('action', 'manage');
});

test('action defaults to connect for unconnected users', function () {
    $user = User::factory()->create(['basiq_user_id' => null]);

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->assertSet('action', 'connect');
});

test('refresh dispatches job and creates log', function () {
    Queue::fake();

    $user = User::factory()->withBasiq()->create();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->call('refresh');

    Queue::assertPushed(RefreshBasiqConnectionsJob::class, function (RefreshBasiqConnectionsJob $job) use ($user) {
        return $job->user->id === $user->id;
    });

    expect(BasiqRefreshLog::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('refresh is no-op at daily limit', function () {
    Queue::fake();

    $user = User::factory()->withBasiq()->create();
    BasiqRefreshLog::factory()->count(20)->for($user)->create();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->call('refresh');

    Queue::assertNothingPushed();
});

test('refresh is no-op for unconnected users', function () {
    Queue::fake();

    $user = User::factory()->create(['basiq_user_id' => null]);

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->call('refresh');

    Queue::assertNothingPushed();
});

test('correct account count is rendered', function () {
    $user = User::factory()->withBasiq()->create();
    Account::factory()->count(3)->for($user)->create();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->assertSee('3');
});

test('refresh logs table renders recent entries', function () {
    $user = User::factory()->withBasiq()->create();
    BasiqRefreshLog::factory()->completed()->for($user)->create();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->assertSee('Refresh History')
        ->assertSee('Success');
});

test('daily counter shows correct count', function () {
    $user = User::factory()->withBasiq()->create();
    BasiqRefreshLog::factory()->count(5)->for($user)->create();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->assertSee('5 of 20');
});
