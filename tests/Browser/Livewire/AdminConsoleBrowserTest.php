<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\LlmOperation;
use App\Models\AuditEvent;
use App\Models\LlmUsage;
use App\Models\User;

beforeEach(function (): void {
    $this->admin = User::factory()->create(['is_admin' => true, 'name' => 'Ada Admin', 'email' => 'ada@example.test']);
    $this->actingAs($this->admin);
});

test('an admin searches users and removes one by typing the email', function () {
    User::factory()->create(['name' => 'Bob Builder', 'email' => 'bob@example.test']);

    visit('/admin/users')
        ->assertSee('Bob Builder')
        ->screenshot(filename: 'admin-users')
        ->click('[data-test="remove-user-button"]')
        ->wait(1)
        ->assertSee('Type bob@example.test to confirm')
        ->fill('[data-test="confirm-email-input"]', 'bob@example.test')
        ->click('[data-test="confirm-remove-button"]')
        ->wait(1)
        ->assertDontSee('Bob Builder')
        ->assertNoJavaScriptErrors();

    expect(User::query()->where('email', 'bob@example.test')->exists())->toBeFalse();
});

test('an admin reads the audit trail and the LLM usage', function () {
    AuditEvent::query()->create(['user_id' => $this->admin->id, 'action' => 'http.login.store', 'outcome' => AuditOutcome::Success, 'created_at' => now()]);
    LlmUsage::query()->create(['user_id' => $this->admin->id, 'operation' => LlmOperation::Chat, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'prompt_tokens' => 1200, 'completion_tokens' => 300, 'cost' => 4321]);

    visit('/admin/audit')
        ->assertSee('http.login.store')
        ->screenshot(filename: 'admin-audit')
        ->assertNoJavaScriptErrors();

    visit('/admin/llm-usage')
        ->assertSee('gpt-4o-mini')
        ->assertSee('$43.21')
        ->screenshot(filename: 'admin-llm-usage')
        ->assertNoJavaScriptErrors();
});
