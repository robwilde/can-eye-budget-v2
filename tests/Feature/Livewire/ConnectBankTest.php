<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\BasiqServiceContract;
use App\DTOs\BasiqUser;
use App\Livewire\ConnectBank;
use App\Models\User;
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
        ->and($query['action'])->toBe('connect')
        ->and($query['state'])->toBe(session('basiq_consent_state'));
});

test('action manage produces correct consent url action param', function () {
    $user = User::factory()->withBasiq()->create();

    fakeBasiqService();

    $response = Livewire::actingAs($user)
        ->test(ConnectBank::class, ['action' => 'manage'])
        ->call('connect');

    $redirectUrl = $response->effects['redirect'];
    parse_str(parse_url($redirectUrl, PHP_URL_QUERY), $query);

    expect($query['action'])->toBe('manage');
});

test('connect bank route requires authentication', function () {
    $this
        ->get(route('connect-bank'))
        ->assertRedirect(route('login'));
});
