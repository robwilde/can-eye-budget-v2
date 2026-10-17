<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

test('the setup guide is public and names each step a tester needs', function () {
    $this->get(route('setup-guide'))
        ->assertOk()
        ->assertSee('Settings > API & MCP')
        ->assertSee('https://app.redbark.com/sign-up', false)
        ->assertSee('https://app.redbark.com/settings/api-mcp', false)
        ->assertSee('Add Connection')
        ->assertSee('A$16')
        ->assertSee('https://myaccount.google.com/apppasswords', false)
        ->assertSee('Last checked');
});

test('the setup guide offers SimpleFIN as an alternative and keeps the API key route', function () {
    $this->get(route('setup-guide'))
        ->assertOk()
        ->assertSee('id="simplefin"', false)
        ->assertSee('id="api-key"', false)
        ->assertSee('https://app.redbark.com/settings/simplefin', false)
        ->assertSee('https://app.redbark.com/settings/api-mcp', false)
        ->assertSee('works once');
});
