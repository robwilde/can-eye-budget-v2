<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('an arbitrary authenticated user cannot view horizon', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
});

test('an allow-listed user can view horizon', function () {
    config(['horizon.authorized_emails' => ['ops@example.com']]);
    $user = User::factory()->create(['email' => 'ops@example.com']);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeTrue();
});
