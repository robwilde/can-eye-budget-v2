<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\Theme;
use App\Models\User;
use Illuminate\Support\Facades\Route;

test('the preferences page saves theme, month start day and clears the month start day', function () {
    $user = User::factory()->create(['month_start_day' => 15]);

    $this->actingAs($user);

    $page = visit('/settings/preferences');

    $page->assertSee('Preferences')
        ->assertSee('Month start day')
        ->screenshot(filename: 'preferences-settings')
        ->select('[wire\\:model="theme"]', 'dark')
        ->select('[wire\\:model="monthStartDay"]', '')
        ->click('[data-test="save-preferences-button"]')
        ->assertSee('Saved.')
        ->assertScript("document.documentElement.classList.contains('dark')", true)
        ->assertNoJavaScriptErrors();

    $fresh = $user->fresh();

    expect($fresh->preferences->theme)->toBe(Theme::Dark)
        ->and($fresh->month_start_day)->toBeNull();
});

test('the preferences page turns preview features on', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit('/settings/preferences');

    $page->assertSee('Preview features')
        ->click('[data-test="preview-features-enabled"]')
        ->click('[data-test="save-preferences-button"]')
        ->assertSee('Saved.')
        ->screenshot(filename: 'preferences-preview-features')
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->hasPreviewFeatures())->toBeTrue();
});

test('a user without preview features lands on the dashboard with the notice', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    config(['budget.goals_enabled' => true]);
    Route::middleware(['web', 'auth', 'preview:budget.goals_enabled'])->get('/_preview-gated', fn (): string => 'gated page');

    visit('/_preview-gated')->assertPathIs('/dashboard')
        ->assertSee('This feature is in preview')
        ->screenshot(filename: 'preview-notice')
        ->assertNoJavaScriptErrors();
});
