<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

function asEnvironment(string $environment): void
{
    app()->detectEnvironment(fn (): string => $environment);
}

function newRegistration(string $email): array
{
    return ['name' => 'New Person', 'email' => $email, 'password' => 'a-Strong-Passw0rd!', 'password_confirmation' => 'a-Strong-Passw0rd!'];
}

it('gates Horizon on the instance admin flag outside local', function () {
    asEnvironment('production');
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();
    $user = User::factory()->create();

    expect(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();

    $this->actingAs($user)->get('/horizon')->assertForbidden();
    $this->actingAs($admin)->get('/horizon')->assertOk();
});

it('keeps Horizon closed to guests outside local', function () {
    asEnvironment('production');

    expect(Gate::allows('viewHorizon'))->toBeFalse();
    $this->get('/horizon')->assertForbidden();
});

it('keeps Horizon open in the local environment', function () {
    asEnvironment('local');

    expect(Gate::forUser(User::factory()->create())->allows('viewHorizon'))->toBeTrue();
});

it('defines the admin gate from the same flag', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    expect(Gate::forUser($admin)->allows('admin'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('admin'))->toBeFalse()
        ->and(Gate::allows('admin'))->toBeFalse();
});

it('does not mass-assign the admin flag', function () {
    app(CreateNewUser::class)->create(newRegistration('first@example.com'));

    $second = app(CreateNewUser::class)->create([...newRegistration('second@example.com'), 'is_admin' => true]);

    expect($second->fresh()->is_admin)->toBeFalse();

    $filled = User::query()->create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret-pass', 'is_admin' => true]);

    expect($filled->fresh()->is_admin)->toBeFalse();
});

it('makes the first registered user an instance admin', function () {
    $first = app(CreateNewUser::class)->create(newRegistration('first@example.com'));
    $second = app(CreateNewUser::class)->create(newRegistration('second@example.com'));

    expect($first->fresh()->is_admin)->toBeTrue()
        ->and($second->fresh()->is_admin)->toBeFalse();
});

it('does not make the first registrant an admin when users already exist', function () {
    User::factory()->create();

    $registered = app(CreateNewUser::class)->create(newRegistration('late@example.com'));

    expect($registered->fresh()->is_admin)->toBeFalse();
});

it('keeps the first user not admin when registration validation fails', function () {
    expect(fn () => app(CreateNewUser::class)->create(newRegistration('not-an-email')))->toThrow(Illuminate\Validation\ValidationException::class);

    $first = app(CreateNewUser::class)->create(newRegistration('first@example.com'));

    expect($first->fresh()->is_admin)->toBeTrue();
});

it('backfills the admin flag from the old Horizon email allowlist', function () {
    $migration = require database_path('migrations/2026_12_09_000000_add_is_admin_to_users_table.php');
    $listed = User::factory()->create(['email' => 'ops@example.com']);
    $alsoListed = User::factory()->create(['email' => 'cto@example.com']);
    $other = User::factory()->create(['email' => 'someone@example.com']);

    Env::getRepository()->set('HORIZON_AUTHORIZED_EMAILS', ' ops@example.com , cto@example.com ,');

    try {
        $migration->down();
        $migration->up();
    } finally {
        Env::getRepository()->clear('HORIZON_AUTHORIZED_EMAILS');
    }

    $flag = fn (User $user): bool => (bool) DB::table('users')->where('id', $user->id)->value('is_admin');

    expect($flag($listed))->toBeTrue()
        ->and($flag($alsoListed))->toBeTrue()
        ->and($flag($other))->toBeFalse();
});

it('leaves every user a non-admin when the old allowlist was empty', function () {
    $migration = require database_path('migrations/2026_12_09_000000_add_is_admin_to_users_table.php');
    $user = User::factory()->create();

    $migration->down();
    $migration->up();

    expect((bool) DB::table('users')->where('id', $user->id)->value('is_admin'))->toBeFalse();
});

it('grants and revokes the admin flag with app:make-admin', function () {
    $user = User::factory()->create(['email' => 'rescue@example.com']);
    $other = User::factory()->create();

    Artisan::call('app:make-admin', ['email' => 'rescue@example.com']);

    expect($user->fresh()->is_admin)->toBeTrue()
        ->and($other->fresh()->is_admin)->toBeFalse();

    Artisan::call('app:make-admin', ['email' => 'rescue@example.com', '--revoke' => true]);

    expect($user->fresh()->is_admin)->toBeFalse();
});

it('fails app:make-admin for an unknown email', function () {
    $this->artisan('app:make-admin', ['email' => 'nobody@example.com'])
        ->expectsOutput('No user has that email.')
        ->assertFailed();
});

it('no longer reads the Horizon email allowlist', function () {
    expect(config('horizon.authorized_emails'))->toBeNull();
});
