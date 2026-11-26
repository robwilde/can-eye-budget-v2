<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\InviteCode;
use App\Models\User;

test('an admin generates an invite code and a visitor registers with it', function () {
    config(['budget.registration_mode' => 'invite_only']);
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    $this->actingAs($admin);

    visit('/admin/invite-codes')
        ->assertSee('Invite codes')
        ->click('[data-test="generate-invite-button"]')
        ->wait(1)
        ->assertSee('Copy this code now')
        ->screenshot(filename: 'invite-codes-admin')
        ->assertNoJavaScriptErrors();

    expect(InviteCode::query()->count())->toBe(1);
});

test('the register page asks for an invite code in invite-only mode', function () {
    config(['budget.registration_mode' => 'invite_only']);

    visit('/register')
        ->assertSee('Invite code')
        ->screenshot(filename: 'register-invite-only')
        ->assertNoJavaScriptErrors();
});
