<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\SupportedLocale;
use App\Models\User;

it('saves a supported browser language to the profile on first visit', function () {
    $user = User::factory()->create();
    expect($user->preferences->locale)->toBeNull();

    $this->actingAs($user)
        ->withHeader('Accept-Language', 'de-DE,de;q=0.9,en;q=0.8')
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<html lang="de"', false);

    expect($user->fresh()->preferences->locale)->toBe(SupportedLocale::German)
        ->and(app()->getLocale())->toBe('de');
});

it('prefers the URL locale over the saved locale', function () {
    $user = User::factory()->create();
    $user->updatePreferences(['locale' => 'en']);

    $this->actingAs($user)
        ->get(route('dashboard', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('<html lang="de"', false);

    expect($user->fresh()->preferences->locale)->toBe(SupportedLocale::English);
});

it('prefers the saved locale over the browser language', function () {
    $user = User::factory()->create();
    $user->updatePreferences(['locale' => 'en']);

    $this->actingAs($user)
        ->withHeader('Accept-Language', 'de')
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<html lang="en"', false);

    expect($user->fresh()->preferences->locale)->toBe(SupportedLocale::English);
});

it('falls back to the app default and saves nothing for an unsupported browser language', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeader('Accept-Language', 'fr-FR,ja;q=0.8')
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<html lang="'.str_replace('_', '-', config('app.locale')).'"', false);

    expect($user->fresh()->preferences->locale)->toBeNull();
});

it('ignores an unsupported URL locale', function () {
    $user = User::factory()->create();
    $user->updatePreferences(['locale' => 'de']);

    $this->actingAs($user)
        ->get(route('dashboard', ['locale' => 'xx']))
        ->assertOk()
        ->assertSee('<html lang="de"', false);
});

it('uses the browser language for a guest without saving anything', function () {
    $this->withHeader('Accept-Language', 'de')
        ->get(route('login'))
        ->assertOk()
        ->assertSee('<html lang="de"', false);
});

it('saves the browser language only to the signed-in user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)->withHeader('Accept-Language', 'de')->get(route('dashboard'))->assertOk();

    expect($other->fresh()->preferences->locale)->toBeNull();
});
