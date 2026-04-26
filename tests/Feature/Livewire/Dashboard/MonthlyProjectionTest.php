<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Livewire\Dashboard\MonthlyProjection;
use App\Models\Account;
use App\Models\User;
use Livewire\Livewire;

test('renders empty-state when primary account is not configured', function () {
    $user = User::factory()->create(['primary_account_id' => null]);

    Livewire::actingAs($user)
        ->test(MonthlyProjection::class)
        ->assertSee('Set your primary account to see your buffer projection');
});

test('chart payload startingBalanceCents equals primary account balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 242000]);
    $user->update(['primary_account_id' => $account->id]);

    $payload = Livewire::actingAs($user->fresh())
        ->test(MonthlyProjection::class)
        ->instance()
        ->chartPayload();

    expect($payload['startingBalanceCents'])->toBe(242000)
        ->and($payload['hasPrimaryAccount'])->toBeTrue();
});

test('chart payload points list has correct shape', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000]);
    $user->update(['primary_account_id' => $account->id]);

    $payload = Livewire::actingAs($user->fresh())
        ->test(MonthlyProjection::class)
        ->instance()
        ->chartPayload();

    expect($payload['points'])->toBeArray()
        ->and($payload['points'])->not->toBeEmpty()
        ->and($payload['points'][0])->toHaveKeys(['x', 'y'])
        ->and($payload['points'][0]['x'])->toBeString()
        ->and($payload['points'][0]['y'])->toBeInt();
});

test('wire:ignore is present on chart container', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000]);
    $user->update(['primary_account_id' => $account->id]);

    Livewire::actingAs($user->fresh())
        ->test(MonthlyProjection::class)
        ->assertSeeHtml('wire:ignore');
});
