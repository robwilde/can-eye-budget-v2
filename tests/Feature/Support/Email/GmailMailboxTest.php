<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\GmailCredential;
use App\Models\User;
use App\Support\Email\GmailMailbox;
use Webklex\IMAP\Facades\Client;
use Webklex\PHPIMAP\Client as ImapClient;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\MessageCollection;

/**
 * An IMAP account whose folders resolve per $folders (path => Folder, or a
 * Throwable the lookup throws) and which must be disconnected exactly once.
 *
 * @param  array<string, Folder|Throwable>  $folders
 */
function fakeImapAccount(array $folders): ImapClient
{
    $client = Mockery::mock(ImapClient::class);
    $client->shouldReceive('connect')->once()->andReturnSelf();
    $client->shouldReceive('disconnect')->once()->andReturnSelf();

    foreach ($folders as $path => $folder) {
        $lookup = $client->shouldReceive('getFolderByPath')->with($path);
        $folder instanceof Throwable ? $lookup->andThrow($folder) : $lookup->andReturn($folder);
    }

    Client::shouldReceive('make')->once()->withArgs(fn (array $config): bool => $config['username'] === 'reader@gmail.com'
        && $config['password'] === 'abcdefghijklmnop'
        && $config['host'] === 'imap.gmail.com')->andReturn($client);

    return $client;
}

function mailbox(): GmailMailbox
{
    return new GmailMailbox('reader@gmail.com', 'abcdefghijklmnop');
}

function fakeImapFolder(WhereQuery $query): Folder
{
    $folder = Mockery::mock(Folder::class);
    $folder->shouldReceive('query')->andReturn($query);

    return $folder;
}

test('the query and limit are forwarded to All Mail and the connection is closed', function () {
    $messages = new MessageCollection;
    $query = Mockery::mock(WhereQuery::class);
    $query->shouldReceive('where')->once()->with('CUSTOM X-GM-RAW', 'from:paypal.com.au after:2026/07/01')->andReturnSelf();
    $query->shouldReceive('limit')->once()->with(500)->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn($messages);
    fakeImapAccount(['[Gmail]/All Mail' => fakeImapFolder($query)]);

    expect(mailbox()->search('from:paypal.com.au after:2026/07/01', 500))->toBe($messages);
});

test('INBOX is searched when All Mail cannot be opened', function () {
    $messages = new MessageCollection;
    $query = Mockery::mock(WhereQuery::class);
    $query->shouldReceive('where')->andReturnSelf();
    $query->shouldReceive('limit')->andReturnSelf();
    $query->shouldReceive('get')->once()->andReturn($messages);
    fakeImapAccount([
        '[Gmail]/All Mail' => new RuntimeException('folder hidden'),
        'INBOX' => fakeImapFolder($query),
    ]);

    expect(mailbox()->search('from:paypal.com.au', 10))->toBe($messages);
});

test('the connection is closed when no folder can be searched', function () {
    fakeImapAccount([
        '[Gmail]/All Mail' => new RuntimeException('folder hidden'),
        'INBOX' => new RuntimeException('folder hidden'),
    ]);

    expect(fn () => mailbox()->search('from:paypal.com.au', 10))
        ->toThrow(RuntimeException::class, 'No searchable Gmail folder found ([Gmail]/All Mail, INBOX).');
});

test('the connection is closed when the query fails', function () {
    $query = Mockery::mock(WhereQuery::class);
    $query->shouldReceive('where')->andReturnSelf();
    $query->shouldReceive('limit')->andReturnSelf();
    $query->shouldReceive('get')->andThrow(new RuntimeException('IMAP server error'));
    fakeImapAccount(['[Gmail]/All Mail' => fakeImapFolder($query)]);

    expect(fn () => mailbox()->search('from:paypal.com.au', 10))
        ->toThrow(RuntimeException::class, 'IMAP server error');
});

test('verify logs in and closes the connection without opening a folder', function () {
    fakeImapAccount([]);

    mailbox()->verify();
});

test('verify surfaces a rejected login and still closes the connection', function () {
    $client = Mockery::mock(ImapClient::class);
    $client->shouldReceive('connect')->once()->andThrow(new AuthFailedException('login refused'));
    $client->shouldReceive('disconnect')->once();
    Client::shouldReceive('make')->once()->andReturn($client);

    expect(fn () => mailbox()->verify())->toThrow(AuthFailedException::class);
});

test('a user without a credential has no mailbox', function () {
    expect(GmailMailbox::forUser(User::factory()->create()))->toBeNull();
});

test("a user mailbox logs in with that user's own decrypted credential", function () {
    $user = User::factory()->create();
    GmailCredential::factory()->for($user)->create(['username' => 'reader@gmail.com', 'app_password' => 'abcdefghijklmnop']);
    fakeImapAccount([]);

    GmailMailbox::forUser($user)->verify();
});
