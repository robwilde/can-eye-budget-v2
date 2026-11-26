<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ApiKeyScope;
use App\Enums\AuditOutcome;
use App\Models\ApiKey;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['budget.api' => true]);
});

function createKey(User $user, string $name = 'laptop', string $scope = 'read', string $expires = '90'): mixed
{
    return Livewire::actingAs($user)->test('pages::settings.api-keys')
        ->set('name', $name)
        ->set('scope', $scope)
        ->set('expiresInDays', $expires)
        ->call('create');
}

it('shows a new API key once and stores only its hash', function () {
    $user = User::factory()->create();

    $component = createKey($user)->assertHasNoErrors();
    $token = $component->get('newToken');
    $key = ApiKey::query()->sole();
    [$id, $plain] = explode('|', $token, 2);
    $row = DB::table('api_keys')->where('id', $key->id)->first();

    expect((int) $id)->toBe($key->id)
        ->and($plain)->toStartWith('cib_')
        ->and($row->token)->toBe(hash('sha256', $plain))
        ->and(json_encode($row))->not->toContain($plain)
        ->and($key->toArray())->not->toHaveKey('token');

    $component->assertSee('data-test="new-api-key"', false);

    $this->actingAs($user)->get(route('api-keys.edit'))->assertOk()->assertDontSee($plain);
});

it('authenticates with the issued token and refuses a wrong one', function () {
    Route::middleware('auth:sanctum')->get('/_whoami', fn () => request()->user()->id);
    $user = User::factory()->create();
    $token = createKey($user)->get('newToken');

    $this->withToken($token)->getJson('/_whoami')->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($token.'x')->getJson('/_whoami')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withoutToken()->getJson('/_whoami')->assertUnauthorized();
});

it('does not authenticate a browser session on a token route', function () {
    Route::middleware('auth:sanctum')->get('/_whoami', fn () => 'ok');

    $this->actingAs(User::factory()->create())->getJson('/_whoami')->assertUnauthorized();
});

it('refuses a duplicate name among the user\'s active keys', function () {
    $user = User::factory()->create();

    createKey($user)->assertHasNoErrors();
    createKey($user)->assertHasErrors(['name']);

    expect(ApiKey::query()->count())->toBe(1);

    ApiKey::query()->sole()->forceFill(['revoked_at' => now()])->save();

    createKey($user)->assertHasNoErrors();

    expect(ApiKey::query()->count())->toBe(2);
});

it('lets two users use the same key name', function () {
    createKey(User::factory()->create())->assertHasNoErrors();
    createKey(User::factory()->create())->assertHasNoErrors();

    expect(ApiKey::query()->count())->toBe(2);
});

it('accepts a name again once the earlier key has expired', function () {
    $user = User::factory()->create();
    ApiKey::factory()->for($user)->expired()->create(['name' => 'laptop']);

    createKey($user)->assertHasNoErrors();
});

it('requires exactly one scope of read or read-write', function (string $scope) {
    $user = User::factory()->create();

    createKey($user, scope: $scope)->assertHasErrors(['scope']);

    expect(ApiKey::query()->count())->toBe(0);
})->with(['none' => [''], 'unknown' => ['admin'], 'both' => ['read,read_write']]);

it('gives a read key only the read ability and a read-write key both', function () {
    $user = User::factory()->create();

    createKey($user, 'reader', 'read');
    createKey($user, 'writer', 'read_write');

    $reader = ApiKey::query()->where('name', 'reader')->sole();
    $writer = ApiKey::query()->where('name', 'writer')->sole();

    expect($reader->scope)->toBe(ApiKeyScope::Read)
        ->and($reader->abilities)->toBe(['read'])
        ->and($writer->abilities)->toBe(['read', 'write']);
});

it('refuses an empty or oversized name and an unknown expiry', function (string $name, string $expires) {
    createKey(User::factory()->create(), $name, 'read', $expires)->assertHasErrors();

    expect(ApiKey::query()->count())->toBe(0);
})->with([
    'empty' => ['   ', '90'],
    'too long' => [str_repeat('a', 61), '90'],
    'odd expiry' => ['ok', '7'],
]);

it('sets an expiry or none', function () {
    $user = User::factory()->create();

    createKey($user, 'a', 'read', '30');
    createKey($user, 'b', 'read', 'never');

    expect(ApiKey::query()->where('name', 'a')->sole()->expires_at->isBetween(now()->addDays(30)->subMinute(), now()->addDays(30)->addMinute()))->toBeTrue()
        ->and(ApiKey::query()->where('name', 'b')->sole()->expires_at)->toBeNull();
});

it('limits the number of active keys', function () {
    $user = User::factory()->create();
    ApiKey::factory()->for($user)->count(ApiKey::MAX_ACTIVE_KEYS)->create();

    createKey($user, 'one-too-many')->assertHasErrors(['name']);
});

it('revokes a key and then it no longer authenticates', function () {
    Route::middleware('auth:sanctum')->get('/_whoami', fn () => 'ok');
    $user = User::factory()->create();
    $token = createKey($user)->get('newToken');
    $key = ApiKey::query()->sole();

    $this->withToken($token)->getJson('/_whoami')->assertOk();

    Livewire::actingAs($user)->test('pages::settings.api-keys')->call('revoke', $key->id);

    expect($key->fresh()->revoked_at)->not->toBeNull();

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/_whoami')->assertUnauthorized();
});

it('does not authenticate an expired key', function () {
    Route::middleware('auth:sanctum')->get('/_whoami', fn () => 'ok');
    $user = User::factory()->create();
    $token = createKey($user)->get('newToken');
    ApiKey::query()->sole()->forceFill(['expires_at' => now()->subSecond()])->save();

    $this->withToken($token)->getJson('/_whoami')->assertUnauthorized();
});

it('refuses to revoke another user\'s API key', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $key = ApiKey::factory()->for($owner)->create();

    $component = Livewire::actingAs($other)->test('pages::settings.api-keys');

    expect(fn () => $component->call('revoke', $key->id))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect($key->fresh()->revoked_at)->toBeNull();
});

it('deletes a user\'s API keys when the user is deleted', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    ApiKey::factory()->for($user)->count(2)->create();
    $kept = ApiKey::factory()->for($other)->create();

    $user->delete();

    expect(ApiKey::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(ApiKey::query()->whereKey($kept->id)->exists())->toBeTrue();
});

it('records an audit event when an API key is created or revoked', function () {
    $user = User::factory()->create();

    createKey($user);
    Livewire::actingAs($user)->test('pages::settings.api-keys')->call('revoke', ApiKey::query()->sole()->id);

    $created = AuditEvent::query()->where('action', 'livewire.pages::settings.api-keys.create')->sole();
    $revoked = AuditEvent::query()->where('action', 'livewire.pages::settings.api-keys.revoke')->sole();

    expect($created->user_id)->toBe($user->id)->and($created->outcome)->toBe(AuditOutcome::Success)
        ->and($revoked->user_id)->toBe($user->id)->and($revoked->outcome)->toBe(AuditOutcome::Success)
        ->and(json_encode(AuditEvent::query()->get()->toArray()))->not->toContain('cib_');
});

it('is not found while the api flag is off', function () {
    config(['budget.api' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('api-keys.edit'))->assertNotFound();
    Livewire::actingAs($user)->test('pages::settings.api-keys')->assertNotFound();
});

it('lists only the signed-in user\'s keys', function () {
    $user = User::factory()->create();
    ApiKey::factory()->for($user)->create(['name' => 'mine']);
    ApiKey::factory()->create(['name' => 'theirs']);

    $this->actingAs($user)->get(route('api-keys.edit'))->assertOk()->assertSee('mine')->assertDontSee('theirs');
});

it('uses the ApiKey model for Sanctum tokens', function () {
    expect(Sanctum::$personalAccessTokenModel)->toBe(ApiKey::class);
});

it('always lists every active key even when many older or newer keys are revoked', function () {
    $user = User::factory()->create();
    $oldActive = ApiKey::factory()->for($user)->create(['name' => 'old-but-active']);
    ApiKey::factory()->for($user)->revoked()->count(55)->create();

    Livewire::actingAs($user)->test('pages::settings.api-keys')->assertSee('old-but-active');

    expect($oldActive->fresh()->revoked_at)->toBeNull();
});
