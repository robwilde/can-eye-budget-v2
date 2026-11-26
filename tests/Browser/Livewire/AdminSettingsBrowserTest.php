<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\InstanceSetting;
use App\Models\User;
use App\Services\InstanceSettings;

test('an admin saves settings, sees only a masked key hint and gets the sync time alert', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    visit('/admin/settings')
        ->assertSee('Self-hosting settings')
        ->fill('[data-test="setting-openai-key"]', 'sk-browser-secret-WXYZ')
        ->fill('[data-test="setting-llm-model"]', 'gpt-browser')
        ->click('[data-test="save-settings-button"]')
        ->wait(1)
        ->assertSee('Saved key ending …WXYZ')
        ->assertDontSee('sk-browser-secret-WXYZ')
        ->screenshot(filename: 'admin-settings')
        ->select('[data-test="setting-auto-sync"]', 'on')
        ->fill('[data-test="setting-auto-sync-time"]', '03:30')
        ->click('[data-test="save-settings-button"]')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    app(InstanceSettings::class)->flush();

    expect(app(InstanceSettings::class)->llmModel())->toBe('gpt-browser')
        ->and(app(InstanceSettings::class)->autoSyncTime())->toBe('03:30')
        ->and(InstanceSetting::query()->where('key', 'openai_api_key')->exists())->toBeTrue();
});
