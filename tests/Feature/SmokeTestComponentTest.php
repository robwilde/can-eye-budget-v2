<?php

/** @noinspection StaticClosureCanBeUsedInspection */

use App\Livewire\SmokeTest;
use App\Models\User;
use Livewire\Livewire;

test('smoke test component renders', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SmokeTest::class)
        ->assertSee('Count: 0')
        ->assertOk();
});

test('smoke test increment action works', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SmokeTest::class)
        ->assertSet('count', 0)
        ->call('increment')
        ->assertSet('count', 1)
        ->call('increment')
        ->assertSet('count', 2);
});

test('smoke test route requires authentication', function () {
    $response = $this->get(route('smoke-test'));

    $response->assertRedirect(route('login'));
});
