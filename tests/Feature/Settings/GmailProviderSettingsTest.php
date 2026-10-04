<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Livewire\GmailConnection;
use App\Models\GmailCredential;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Webklex\IMAP\Facades\Client;
use Webklex\PHPIMAP\Client as ImapClient;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;

const GMAIL_TEST_PASSWORD = 'abcdefghijklmnop';

function fakeGmailLogin(?Throwable $failure = null): void
{
    $client = Mockery::mock(ImapClient::class);
    $login = $client->shouldReceive('connect')->once();
    $failure instanceof Throwable ? $login->andThrow($failure) : $login->andReturnSelf();
    $client->shouldReceive('disconnect')->once();
    Client::shouldReceive('make')->once()->andReturn($client);
}

function neverConnectToGmail(): void
{
    Client::shouldReceive('make')->never();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('the providers page renders the Gmail panel', function () {
    $this->get(route('providers.edit'))
        ->assertOk()
        ->assertSeeLivewire('gmail-connection')
        ->assertSee('Connect Gmail');
});

test('a user can connect their mailbox and the app password is stored encrypted', function (string $separator) {
    fakeGmailLogin();

    Livewire::test(GmailConnection::class)
        ->set('username', 'me@gmail.com')
        ->set('app_password', implode($separator, ['abcd', 'efgh', 'ijkl', 'mnop']))
        ->call('connect')
        ->assertHasNoErrors()
        ->assertSet('app_password', '')
        ->assertDispatched('gmail-saved')
        ->assertSee('Connected')
        ->assertSee('me@gmail.com');

    $credential = GmailCredential::query()->where('user_id', $this->user->id)->sole();
    $stored = $credential->getRawOriginal('app_password');

    expect($credential->username)->toBe('me@gmail.com')
        ->and($credential->app_password)->toBe(GMAIL_TEST_PASSWORD)
        ->and($credential->last_verified_at)->not->toBeNull()
        ->and($stored)->not->toBe(GMAIL_TEST_PASSWORD)
        ->and($stored)->not->toContain(GMAIL_TEST_PASSWORD);
})->with([
    'spaces' => ' ',
    'no-break spaces' => "\u{00A0}",
    'narrow no-break spaces' => "\u{202F}",
]);

test('a Gmail login rejection gives an inline error and stores nothing', function (Closure $failure) {
    fakeGmailLogin($failure());

    Livewire::test(GmailConnection::class)
        ->set('username', 'me@gmail.com')
        ->set('app_password', GMAIL_TEST_PASSWORD)
        ->call('connect')
        ->assertHasErrors(['app_password'])
        ->assertSee('Gmail rejected these details');

    expect(GmailCredential::query()->count())->toBe(0);
})->with([
    'server NO [AUTHENTICATIONFAILED] as ImapProtocol raises it' => [fn (): Throwable => new ImapServerErrorException('NO [AUTHENTICATIONFAILED] Invalid credentials (Failure)')],
    'app password required' => [fn (): Throwable => new ImapServerErrorException('NO [ALERT] Application-specific password required')],
    'AuthFailedException' => [fn (): Throwable => new AuthFailedException('failed to authenticate')],
    'AuthFailedException wrapped by another exception' => [fn (): Throwable => new ConnectionFailedException('connection setup failed', 0, new AuthFailedException('failed to authenticate'))],
]);

test('a server error that is not a login rejection is reported as unreachable', function () {
    fakeGmailLogin(new ImapServerErrorException('BYE Server shutting down'));

    Livewire::test(GmailConnection::class)
        ->set('username', 'me@gmail.com')
        ->set('app_password', GMAIL_TEST_PASSWORD)
        ->call('connect')
        ->assertHasErrors(['app_password'])
        ->assertSee('Could not reach Gmail');

    expect(GmailCredential::query()->count())->toBe(0);
});

test('an unreachable Gmail gives an inline error and stores nothing', function () {
    fakeGmailLogin(new ConnectionFailedException('timed out'));

    Livewire::test(GmailConnection::class)
        ->set('username', 'me@gmail.com')
        ->set('app_password', GMAIL_TEST_PASSWORD)
        ->call('connect')
        ->assertHasErrors(['app_password'])
        ->assertSee('Could not reach Gmail');

    expect(GmailCredential::query()->count())->toBe(0);
});

test('a failed update leaves the working credential untouched', function () {
    GmailCredential::factory()->for($this->user)->create(['username' => 'old@gmail.com', 'app_password' => 'oldoldoldoldoldo']);
    fakeGmailLogin(new AuthFailedException('AUTHENTICATIONFAILED'));

    Livewire::test(GmailConnection::class)
        ->set('username', 'new@gmail.com')
        ->set('app_password', GMAIL_TEST_PASSWORD)
        ->call('connect')
        ->assertHasErrors(['app_password']);

    $credential = $this->user->gmailCredential()->sole();

    expect($credential->username)->toBe('old@gmail.com')
        ->and($credential->app_password)->toBe('oldoldoldoldoldo');
});

test('the connect form validates before touching Gmail', function () {
    neverConnectToGmail();

    Livewire::test(GmailConnection::class)
        ->set('username', 'not-an-email')
        ->set('app_password', '')
        ->call('connect')
        ->assertHasErrors(['username' => 'email', 'app_password' => 'required']);
});

test('reconnecting replaces the credential instead of adding a second', function () {
    GmailCredential::factory()->for($this->user)->create(['username' => 'old@gmail.com']);
    fakeGmailLogin();

    Livewire::test(GmailConnection::class)
        ->set('username', 'new@gmail.com')
        ->set('app_password', GMAIL_TEST_PASSWORD)
        ->call('connect')
        ->assertHasNoErrors();

    expect(GmailCredential::query()->where('user_id', $this->user->id)->sole()->username)->toBe('new@gmail.com');
});

test('testing a working connection records when it was last checked', function () {
    $credential = GmailCredential::factory()->for($this->user)->create();
    fakeGmailLogin();

    Livewire::test(GmailConnection::class)
        ->call('test')
        ->assertSet('testPassed', true)
        ->assertSee('Connection works.');

    expect($credential->fresh()->last_verified_at)->not->toBeNull();
});

test('testing a broken connection reports it without disconnecting', function () {
    $credential = GmailCredential::factory()->for($this->user)->create();
    fakeGmailLogin(new AuthFailedException('AUTHENTICATIONFAILED'));

    Livewire::test(GmailConnection::class)
        ->call('test')
        ->assertSet('testPassed', false)
        ->assertSee('Gmail rejected these details');

    expect($credential->fresh())->not->toBeNull()
        ->and($credential->fresh()->last_verified_at)->toBeNull();
});

test('a failed save resets the password and shows an inline error without storing anything', function () {
    fakeGmailLogin();
    GmailCredential::saving(fn () => throw new RuntimeException('disk full '.GMAIL_TEST_PASSWORD));

    $component = Livewire::test(GmailConnection::class)
        ->set('username', 'typed@gmail.com')
        ->set('app_password', GMAIL_TEST_PASSWORD)
        ->call('connect')
        ->assertSet('app_password', '')
        ->assertHasErrors('app_password')
        ->assertSee('Could not save your Gmail connection. Try again.')
        ->assertNotDispatched('gmail-saved');

    expect(json_encode($component->snapshot))->not->toContain(GMAIL_TEST_PASSWORD)
        ->and(GmailCredential::query()->count())->toBe(0);
});

test('deleting a user deletes their Gmail credential', function () {
    GmailCredential::factory()->for($this->user)->create();
    $other = GmailCredential::factory()->create();

    $this->user->delete();

    expect(GmailCredential::query()->where('user_id', $this->user->id)->exists())->toBeFalse()
        ->and(GmailCredential::query()->whereKey($other->id)->exists())->toBeTrue();
});

test('testing without a connection never reaches Gmail', function () {
    neverConnectToGmail();

    Livewire::test(GmailConnection::class)->call('test')->assertSet('testMessage', null);
});

test('disconnecting deletes the stored credential and only the caller\'s own', function () {
    GmailCredential::factory()->for($this->user)->create();
    $other = GmailCredential::factory()->create();

    Livewire::test(GmailConnection::class)
        ->call('disconnect')
        ->assertDispatched('gmail-disconnected')
        ->assertSee('Connect Gmail');

    expect(GmailCredential::query()->where('user_id', $this->user->id)->exists())->toBeFalse()
        ->and(GmailCredential::query()->whereKey($other->id)->exists())->toBeTrue();
});

test('disconnecting clears typed credentials from the component state', function () {
    $component = Livewire::test(GmailConnection::class)
        ->set('username', 'typed@gmail.com')
        ->set('app_password', GMAIL_TEST_PASSWORD)
        ->call('disconnect')
        ->assertSet('username', '')
        ->assertSet('app_password', '');

    expect(json_encode($component->snapshot))->not->toContain(GMAIL_TEST_PASSWORD)->not->toContain('typed@gmail.com');
});

test('the app password never appears in serialised models', function () {
    $credential = GmailCredential::factory()->for($this->user)->create(['app_password' => GMAIL_TEST_PASSWORD]);

    expect($credential->toArray())->not->toHaveKey('app_password')
        ->and($credential->toJson())->not->toContain(GMAIL_TEST_PASSWORD)
        ->and(json_encode($this->user->load('gmailCredential')->toArray()))->not->toContain(GMAIL_TEST_PASSWORD);
});

test('the app password reaches neither the log nor the component state after a failed connect', function () {
    Log::spy();
    fakeGmailLogin(new ConnectionFailedException('timed out'));

    $component = Livewire::test(GmailConnection::class)
        ->set('username', 'me@gmail.com')
        ->set('app_password', GMAIL_TEST_PASSWORD)
        ->call('connect')
        ->assertSet('app_password', '');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => ! str_contains($message.json_encode($context), GMAIL_TEST_PASSWORD))
        ->once();

    expect(json_encode($component->snapshot))->not->toContain(GMAIL_TEST_PASSWORD)
        ->and($component->html())->not->toContain(GMAIL_TEST_PASSWORD);
});

test('the app password is dropped from component state when validation fails', function () {
    neverConnectToGmail();

    $component = Livewire::test(GmailConnection::class)
        ->set('username', 'not-an-email')
        ->set('app_password', GMAIL_TEST_PASSWORD)
        ->call('connect')
        ->assertHasErrors(['username'])
        ->assertSet('app_password', '');

    expect(json_encode($component->snapshot))->not->toContain(GMAIL_TEST_PASSWORD);
});

test('the app password is never rendered back for a stored credential', function () {
    GmailCredential::factory()->for($this->user)->create(['app_password' => GMAIL_TEST_PASSWORD]);

    $component = Livewire::test(GmailConnection::class);

    expect(json_encode($component->snapshot))->not->toContain(GMAIL_TEST_PASSWORD)
        ->and($component->html())->not->toContain(GMAIL_TEST_PASSWORD);
});
