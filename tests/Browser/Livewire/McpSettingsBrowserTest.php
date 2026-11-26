<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ApiKeyScope;
use App\Models\ApiKey;
use App\Models\User;

test('the API keys page shows the MCP address and the connected keys', function () {
    config(['budget.api' => true, 'budget.mcp' => true]);
    $user = User::factory()->create();
    ApiKey::issue($user, 'Claude Desktop', ApiKeyScope::Read, null);

    $this->actingAs($user);

    visit('/settings/api-keys')
        ->assertSee('MCP server')
        ->assertSee('/mcp')
        ->assertSee('Claude Desktop')
        ->screenshot(filename: 'mcp-settings')
        ->assertNoJavaScriptErrors();
});
