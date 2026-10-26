<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Currencies;
use Livewire\Livewire;

test('currency settings page is displayed', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('currency.edit'))->assertOk()->assertSee('Base currency');
});

test('unauthenticated user is redirected from currency settings', function () {
    $this->get(route('currency.edit'))->assertRedirect(route('login'));
});

test('user can save a base currency and allowed currencies', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.currency')
        ->assertSet('base_currency', 'AUD')
        ->set('base_currency', 'EUR')
        ->set('allowed', ['USD', 'GBP'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('currency-updated')
        ->assertSet('allowed', ['EUR', 'USD', 'GBP']);

    $user->refresh();

    expect($user->base_currency)->toBe('EUR')
        ->and($user->preferences->allowedCurrencies)->toBe(['EUR', 'USD', 'GBP'])
        ->and($user->allowedCurrencies())->toBe(['EUR', 'USD', 'GBP']);
});

test('leaving every currency unticked offers all of them', function () {
    $user = User::factory()->withPreferences(['allowedCurrencies' => ['EUR']])->create();

    Livewire::actingAs($user)->test('pages::settings.currency')->set('allowed', [])->call('save')->assertHasNoErrors();

    expect($user->fresh()->preferences->allowedCurrencies)->toBe([])
        ->and($user->fresh()->allowedCurrencies())->toEqualCanonicalizing(Currencies::codes());
});

test('stores the base currency per user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.currency')->set('base_currency', 'NZD')->call('save');

    expect($user->fresh()->base_currency)->toBe('NZD')
        ->and($other->fresh()->base_currency)->toBe('AUD');
});

test('rejects a currency that is not supported', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.currency')
        ->set('base_currency', 'XXX')
        ->call('save')
        ->assertHasErrors(['base_currency']);

    Livewire::actingAs($user)
        ->test('pages::settings.currency')
        ->set('allowed', ['EUR', 'ZZZ'])
        ->call('save')
        ->assertHasErrors(['allowed.1']);

    expect($user->fresh()->base_currency)->toBe('AUD');
});

test('records an audit event when the base currency changes', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.currency')->set('base_currency', 'EUR')->call('save');

    $event = AuditEvent::query()->where('action', 'livewire.pages::settings.currency.save')->sole();

    expect($event->user_id)->toBe($user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});
