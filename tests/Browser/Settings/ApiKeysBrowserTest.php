<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\ApiKey;
use App\Models\User;

test('a user creates an API key and sees it once', function () {
    config(['budget.api' => true]);
    $user = User::factory()->create();

    $this->actingAs($user);

    visit('/settings/api-keys')
        ->assertSee('API keys')
        ->fill('[data-test="api-key-name"]', 'laptop')
        ->select('[data-test="api-key-scope"]', 'read_write')
        ->click('[data-test="create-api-key-button"]')
        ->wait(1)
        ->assertSee('Copy this key now')
        ->screenshot(filename: 'api-keys')
        ->assertNoJavaScriptErrors();

    expect(ApiKey::query()->where('user_id', $user->id)->count())->toBe(1);
});
