<?php

declare(strict_types=1);

use App\DTOs\UserPreferences;
use App\Enums\Theme;
use App\Enums\TransactionPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('returns default preferences when the column is null', function () {
    $user = User::factory()->create();

    $preferences = $user->fresh()->preferences;

    expect($preferences)->toBeInstanceOf(UserPreferences::class)
        ->and($preferences->theme)->toBe(Theme::System)
        ->and($preferences->previewFeatures)->toBeFalse()
        ->and($preferences->rulePromptEnabled)->toBeTrue();
});

test('merges a single preference without clearing the others', function () {
    $user = User::factory()->withPreferences(['theme' => 'dark', 'previewFeatures' => true])->create();

    $user->updatePreferences(['theme' => 'light']);

    $fresh = $user->fresh()->preferences;
    expect($fresh->theme)->toBe(Theme::Light)
        ->and($fresh->previewFeatures)->toBeTrue();
});

test('falls back to the app timezone when the user has none', function () {
    config(['app.timezone' => 'Australia/Sydney']);

    expect(User::factory()->create()->resolvedTimezone())->toBe('Australia/Sydney')
        ->and(User::factory()->withTimezone('Australia/Perth')->create()->resolvedTimezone())->toBe('Australia/Perth');
});

test('rejects a month start day outside 1 to 28', function (int $day) {
    $user = User::factory()->withMonthStartDay(15)->create();

    expect(fn () => $user->update(['month_start_day' => $day]))->toThrow(InvalidArgumentException::class)
        ->and($user->fresh()->month_start_day)->toBe(15);
})->with([0, 29]);

test('accepts the month start day boundaries', function (int $day) {
    $user = User::factory()->create();

    $user->update(['month_start_day' => $day]);

    expect($user->fresh()->month_start_day)->toBe($day);
})->with([1, 28]);

test('defaults the base currency to AUD', function () {
    expect(User::factory()->create()->fresh()->base_currency)->toBe('AUD');
});

test('changes only the acting user\'s preferences', function () {
    $acting = User::factory()->create();
    $other = User::factory()->withPreferences(['theme' => 'dark'])->create();

    $acting->updatePreferences(['theme' => 'light', 'previewFeatures' => true]);

    $untouched = $other->fresh()->preferences;
    expect($untouched->theme)->toBe(Theme::Dark)
        ->and($untouched->previewFeatures)->toBeFalse();
});

test('keeps a concurrent preference change made through another instance', function () {
    $user = User::factory()->create();
    $first = User::query()->findOrFail($user->id);
    $second = User::query()->findOrFail($user->id);

    $first->updatePreferences(['theme' => 'dark']);
    $second->updatePreferences(['previewFeatures' => true]);

    $fresh = $user->fresh()->preferences;
    expect($fresh->theme)->toBe(Theme::Dark)
        ->and($fresh->previewFeatures)->toBeTrue();
});

test('does not write other dirty attributes when updating preferences', function () {
    $user = User::factory()->create(['name' => 'Original']);

    $user->name = 'Unsaved';
    $user->updatePreferences(['theme' => 'dark']);

    expect($user->fresh()->name)->toBe('Original');
});

test('falls back to defaults for stored values that are no longer valid', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update([
        'preferences' => json_encode(['theme' => 'retired', 'defaultPeriod' => null, 'previewFeatures' => true]),
    ]);

    $preferences = $user->fresh()->preferences;

    expect($preferences->theme)->toBe(Theme::System)
        ->and($preferences->defaultPeriod)->toBe(TransactionPeriod::ThisMonth)
        ->and($preferences->previewFeatures)->toBeTrue();
});

test('reading default preferences does not make the user dirty', function () {
    $user = User::factory()->create()->fresh();

    $user->preferences;

    expect($user->isDirty())->toBeFalse();
});
