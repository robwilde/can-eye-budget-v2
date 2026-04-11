<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\BasiqAccount;
use App\DTOs\BasiqJob;
use App\DTOs\BasiqTransaction;
use App\DTOs\BasiqUser;
use App\Services\BasiqService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::forget('basiq:server_token');
});

test('serverToken sends correct POST with Basic auth and SERVER_ACCESS scope', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'server-tok-123']),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    $token = $service->serverToken();

    expect($token)->toBe('server-tok-123');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://au-api.basiq.io/token'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Basic test-api-key')
            && $request->hasHeader('basiq-version', '3.0')
            && $request['scope'] === 'SERVER_ACCESS';
    });
});

test('serverToken caches token and does not make a second HTTP request', function () {
    Http::fake([
        '*/token' => Http::sequence()
            ->push(['access_token' => 'cached-tok'])
            ->push(['access_token' => 'fresh-tok']),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    $first = $service->serverToken();
    $second = $service->serverToken();

    expect($first)
        ->toBe('cached-tok')
        ->and($second)->toBe('cached-tok');

    Http::assertSentCount(1);
});

test('serverToken makes fresh request after cache clear', function () {
    Http::fake([
        '*/token' => Http::sequence()
            ->push(['access_token' => 'first-tok'])
            ->push(['access_token' => 'second-tok']),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    $first = $service->serverToken();
    Cache::forget('basiq:server_token');
    $second = $service->serverToken();

    expect($first)
        ->toBe('first-tok')
        ->and($second)->toBe('second-tok');

    Http::assertSentCount(2);
});

test('clientToken sends correct POST with CLIENT_ACCESS scope and userId', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'client-tok-456']),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    $token = $service->clientToken('basiq-user-789');

    expect($token)->toBe('client-tok-456');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://au-api.basiq.io/token'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Basic test-api-key')
            && $request->hasHeader('basiq-version', '3.0')
            && $request['scope'] === 'CLIENT_ACCESS'
            && $request['userId'] === 'basiq-user-789';
    });
});

test('clientToken is not cached and makes a fresh request each time', function () {
    Http::fake([
        '*/token' => Http::sequence()
            ->push(['access_token' => 'client-tok-1'])
            ->push(['access_token' => 'client-tok-2']),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    $first = $service->clientToken('user-1');
    $second = $service->clientToken('user-1');

    expect($first)
        ->toBe('client-tok-1')
        ->and($second)->toBe('client-tok-2');

    Http::assertSentCount(2);
});

test('api returns a PendingRequest instance', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'api-tok']),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    expect($service->api())->toBeInstanceOf(PendingRequest::class);
});

test('api attaches bearer token from serverToken', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'bearer-tok']),
        '*/users' => Http::response(['data' => []]),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');
    $service->api()->get('/users');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://au-api.basiq.io/users'
            && $request->hasHeader('Authorization', 'Bearer bearer-tok')
            && $request->hasHeader('basiq-version', '3.0');
    });
});

test('serverToken throws RequestException on HTTP error', function () {
    Http::fake([
        '*/token' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    $service = new BasiqService(apiKey: 'bad-key', baseUrl: 'https://au-api.basiq.io');

    $service->serverToken();
})->throws(RequestException::class);

test('clientToken throws RequestException on HTTP error', function () {
    Http::fake([
        '*/token' => Http::response(['error' => 'forbidden'], 403),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    $service->clientToken('invalid-user');
})->throws(RequestException::class);

test('serverToken throws RuntimeException when access_token is missing', function () {
    Http::fake([
        '*/token' => Http::response(['data' => 'no-token-here']),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    $service->serverToken();
})->throws(RuntimeException::class, 'Basiq token response missing access_token for scope: SERVER_ACCESS');

test('clientToken throws RuntimeException when access_token is missing', function () {
    Http::fake([
        '*/token' => Http::response(['data' => 'no-token-here']),
    ]);

    $service = new BasiqService(apiKey: 'test-api-key', baseUrl: 'https://au-api.basiq.io');

    $service->clientToken('basiq-user-789');
})->throws(RuntimeException::class, 'Basiq token response missing access_token for scope: CLIENT_ACCESS');

test('service is resolvable from container as singleton', function () {
    $first = app(BasiqService::class);
    $second = app(BasiqService::class);

    expect($first)
        ->toBeInstanceOf(BasiqService::class)
        ->and($first)->toBe($second);
});

test('createUser sends POST with email and returns BasiqUser', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/users' => Http::response(['id' => 'usr-1', 'email' => 'jane@example.com']),
    ]);

    $service = new BasiqService(apiKey: 'key', baseUrl: 'https://au-api.basiq.io');
    $user = $service->createUser('jane@example.com');

    expect($user)
        ->toBeInstanceOf(BasiqUser::class)
        ->id->toBe('usr-1')
        ->email->toBe('jane@example.com')
        ->mobile->toBeNull();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://au-api.basiq.io/users'
        && $r->method() === 'POST'
        && $r['email'] === 'jane@example.com'
        && ! isset($r['mobile']));
});

test('createUser includes mobile when provided', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/users' => Http::response(['id' => 'usr-2', 'email' => 'joe@example.com', 'mobile' => '+61400000000']),
    ]);

    $service = new BasiqService(apiKey: 'key', baseUrl: 'https://au-api.basiq.io');
    $user = $service->createUser('joe@example.com', '+61400000000');

    expect($user->mobile)->toBe('+61400000000');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://au-api.basiq.io/users'
        && $r['mobile'] === '+61400000000');
});

test('createUser throws RequestException on API error', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/users' => Http::response(['error' => 'invalid'], 422),
    ]);

    $service = new BasiqService(apiKey: 'key', baseUrl: 'https://au-api.basiq.io');
    $service->createUser('bad@example.com');
})->throws(RequestException::class);

test('getAccounts returns collection of BasiqAccount DTOs', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/users/usr-1/accounts' => Http::response([
            'data' => [
                ['id' => 'acc-1', 'name' => 'Savings', 'class' => ['type' => 'savings'], 'balance' => '5000', 'currency' => 'AUD', 'status' => 'active'],
                ['id' => 'acc-2', 'name' => 'Credit', 'type' => 'credit', 'currency' => 'AUD'],
            ],
        ]),
    ]);

    $service = new BasiqService(apiKey: 'key', baseUrl: 'https://au-api.basiq.io');
    $accounts = $service->getAccounts('usr-1');

    expect($accounts)
        ->toHaveCount(2)
        ->each
        ->toBeInstanceOf(BasiqAccount::class)
        ->and($accounts->first())
        ->id->toBe('acc-1')
        ->type->toBe('savings')
        ->balance->toBe('5000');
});

test('getAccounts returns empty collection when no accounts', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/users/usr-1/accounts' => Http::response(['data' => []]),
    ]);

    $service = new BasiqService(apiKey: 'key', baseUrl: 'https://au-api.basiq.io');
    $accounts = $service->getAccounts('usr-1');

    expect($accounts)->toBeEmpty();
});

test('paginateTransactions returns transactions from single page', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/users/usr-1/transactions*' => Http::response([
            'data' => [
                ['id' => 'txn-1', 'amount' => '-10.00', 'direction' => 'debit'],
                ['id' => 'txn-2', 'amount' => '20.00', 'direction' => 'credit'],
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $service = new BasiqService(apiKey: 'key', baseUrl: 'https://au-api.basiq.io');
    $transactions = $service->paginateTransactions('usr-1')->all();

    expect($transactions)
        ->toHaveCount(2)
        ->each->toBeInstanceOf(BasiqTransaction::class);
});

test('paginateTransactions follows pagination links across multiple pages', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/users/usr-1/transactions*' => Http::sequence()
            ->push([
                'data' => [['id' => 'txn-1', 'amount' => '10.00', 'direction' => 'debit']],
                'links' => ['next' => 'https://au-api.basiq.io/users/usr-1/transactions?cursor=abc'],
            ])
            ->push([
                'data' => [['id' => 'txn-2', 'amount' => '20.00', 'direction' => 'credit']],
                'links' => ['next' => null],
            ]),
    ]);

    $service = new BasiqService(apiKey: 'key', baseUrl: 'https://au-api.basiq.io');
    $transactions = $service->paginateTransactions('usr-1')->all();

    expect($transactions)
        ->toHaveCount(2)
        ->and($transactions[0]->id)->toBe('txn-1')
        ->and($transactions[1]->id)->toBe('txn-2');
});

test('paginateTransactions sends filter as comma-separated query param', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/users/usr-1/transactions*' => Http::response([
            'data' => [],
            'links' => ['next' => null],
        ]),
    ]);

    $service = new BasiqService(apiKey: 'key', baseUrl: 'https://au-api.basiq.io');
    $service->paginateTransactions('usr-1', ['account.id.eq(acc-1)', 'direction.eq(debit)'])->all();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'transactions')
        && str_contains($r->url(), 'filter=account.id.eq%28acc-1%29%2Cdirection.eq%28debit%29'));
});

test('paginateTransactions sends no filter param when null', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/users/usr-1/transactions*' => Http::response([
            'data' => [],
            'links' => ['next' => null],
        ]),
    ]);

    $service = new BasiqService(apiKey: 'key', baseUrl: 'https://au-api.basiq.io');
    $service->paginateTransactions('usr-1')->all();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'transactions')
        && ! str_contains($r->url(), 'filter='));
});

test('paginateTransactions stops when data is empty even if next link exists', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/users/usr-1/transactions*' => Http::sequence()
            ->push([
                'data' => [['id' => 'txn-1', 'amount' => '10.00', 'direction' => 'debit']],
                'links' => ['next' => 'https://au-api.basiq.io/users/usr-1/transactions?next=cursor'],
            ])
            ->push([
                'data' => [],
                'links' => ['next' => 'https://au-api.basiq.io/users/usr-1/transactions?next=cursor2'],
            ]),
    ]);

    $service = new BasiqService(apiKey: 'key', baseUrl: 'https://au-api.basiq.io');
    $transactions = $service->paginateTransactions('usr-1')->all();

    expect($transactions)->toHaveCount(1);
    Http::assertSentCount(3);
});

test('createConnection sends correct POST payload and returns job ID', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/users/usr-1/connections' => Http::response([
            'id' => 'conn-1',
            'links' => ['job' => 'https://au-api.basiq.io/jobs/job-abc-123'],
        ]),
    ]);

    $service = new BasiqService(apiKey: 'key', baseUrl: 'https://au-api.basiq.io');
    $jobId = $service->createConnection('usr-1', 'AU00000', 'Wentworth-Smith', 'whislter');

    expect($jobId)->toBe('job-abc-123');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://au-api.basiq.io/users/usr-1/connections'
        && $r->method() === 'POST'
        && $r['institution']['id'] === 'AU00000'
        && $r['loginId'] === 'Wentworth-Smith'
        && $r['password'] === 'whislter');
});

test('createConnection throws RequestException on API error', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/users/usr-1/connections' => Http::response(['error' => 'invalid'], 422),
    ]);

    $service = new BasiqService(apiKey: 'key', baseUrl: 'https://au-api.basiq.io');
    $service->createConnection('usr-1', 'AU00000', 'bad-login', 'bad-pass');
})->throws(RequestException::class);

test('createConnection throws RuntimeException when job link is missing', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/users/usr-1/connections' => Http::response([
            'id' => 'conn-1',
            'links' => [],
        ]),
    ]);

    $service = new BasiqService(apiKey: 'key', baseUrl: 'https://au-api.basiq.io');
    $service->createConnection('usr-1', 'AU00000', 'Wentworth-Smith', 'whislter');
})->throws(RuntimeException::class, 'Basiq connection response missing job link for user: usr-1');

test('getJob returns BasiqJob with derived status', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/jobs/job-1' => Http::response([
            'id' => 'job-1',
            'steps' => [
                ['title' => 'verify-credentials', 'status' => 'success'],
                ['title' => 'retrieve-accounts', 'status' => 'success'],
            ],
        ]),
    ]);

    $service = new BasiqService(apiKey: 'key', baseUrl: 'https://au-api.basiq.io');
    $job = $service->getJob('job-1');

    expect($job)
        ->toBeInstanceOf(BasiqJob::class)
        ->id->toBe('job-1')
        ->status->toBe('success')
        ->steps->toHaveCount(2);
});

test('getJob throws RequestException on 404', function () {
    Http::fake([
        '*/token' => Http::response(['access_token' => 'tok']),
        '*/jobs/bad-id' => Http::response(['error' => 'not found'], 404),
    ]);

    $service = new BasiqService(apiKey: 'key', baseUrl: 'https://au-api.basiq.io');
    $service->getJob('bad-id');
})->throws(RequestException::class);
