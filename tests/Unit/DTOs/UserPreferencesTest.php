<?php

declare(strict_types=1);

use App\Casts\UserPreferencesCast;
use App\DTOs\UserPreferences;
use App\Enums\Theme;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

uses(TestCase::class);

test('ignores unknown preference keys and defaults missing ones', function () {
    $cast = new UserPreferencesCast;
    $model = new class extends Model {};

    $preferences = $cast->get($model, 'preferences', json_encode(['theme' => 'dark', 'removedKey' => 'x']), []);

    expect($preferences->theme)->toBe(Theme::Dark)
        ->and($preferences->rulePromptEnabled)->toBeTrue()
        ->and($preferences->previewFeatures)->toBeFalse()
        ->and($preferences->toArray())->not->toHaveKey('removedKey');
});

test('stores preferences as scalar JSON that reads back identically', function () {
    $cast = new UserPreferencesCast;
    $model = new class extends Model {};

    $stored = $cast->set($model, 'preferences', UserPreferences::from(['theme' => 'light', 'previewFeatures' => true]), []);
    $roundTripped = $cast->get($model, 'preferences', $stored, []);

    expect(json_decode($stored, true))->toMatchArray(['theme' => 'light', 'previewFeatures' => true, 'defaultPeriod' => 'this-month'])
        ->and($roundTripped->theme)->toBe(Theme::Light)
        ->and($roundTripped->previewFeatures)->toBeTrue();
});

test('a null stored value stays null when written', function () {
    $cast = new UserPreferencesCast;
    $model = new class extends Model {};

    expect($cast->set($model, 'preferences', null, []))->toBeNull();
});
