<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\RedbarkAccountData;
use App\Exceptions\Redbark\RedbarkAuthenticationException;
use App\Exceptions\Redbark\RedbarkException;
use App\Exceptions\Redbark\RedbarkRateLimitException;
use App\Exceptions\Redbark\RedbarkRequestException;
use App\Exceptions\Redbark\RedbarkServerException;
use App\Services\RedbarkService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function redbarkFixture(string $name): array
{
    return json_decode(
        (string) file_get_contents(base_path("tests/Fixtures/Redbark/$name.json")),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

function redbarkService(): RedbarkService
{
    return new RedbarkService(apiKey: 'rbk_live_test', baseUrl: 'https://api.redbark.com/v1');
}

/** @return list<array<string, mixed>> */
function redbarkRows(int $count, string $prefix = 'row'): array
{
    return array_map(
        static fn (int $i): array => ['id' => "$prefix-$i", 'name' => "Account $i", 'currency' => 'AUD'],
        range(1, $count),
    );
}

test('listAccounts parses the captured payload into DTOs', function () {
    $fixture = redbarkFixture('accounts');

    Http::fake(['*/accounts*' => Http::response($fixture)]);

    $accounts = redbarkService()->listAccounts();

    expect($accounts)->toHaveCount(count($fixture['data']))
        ->and($accounts[0])->toBeInstanceOf(RedbarkAccountData::class)
        ->and($accounts[0]->id)->toBe($fixture['data'][0]['id'])
        ->and($accounts[0]->connectionId)->toBe($fixture['data'][0]['connectionId'])
        ->and($accounts[0]->currency)->toBe($fixture['data'][0]['currency'])
        ->and($accounts[0]->accountNumber)->toBe($fixture['data'][0]['accountNumber']);
});

test('listAccounts normalises a currency delivered as an object', function () {
    Http::fake(['*/accounts*' => Http::response([
        'data' => [['id' => 'a1', 'name' => 'Everyday', 'currency' => ['code' => 'aud']]],
        'pagination' => ['hasMore' => false],
    ])]);

    expect(redbarkService()->listAccounts()[0]->currency)->toBe('AUD');
});

test('listAccounts follows pagination and advances the offset by the rows returned', function () {
    Http::fake(['*/accounts*' => Http::sequence()
        ->push(['data' => redbarkRows(200), 'pagination' => ['hasMore' => true]])
        ->push(['data' => redbarkRows(3, 'tail'), 'pagination' => ['hasMore' => false]]),
    ]);

    expect(redbarkService()->listAccounts())->toHaveCount(203);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'offset=200')
        && str_contains($request->url(), 'limit=200'));
});

test('listAccounts throws truncated when the server reports more results but sends none', function () {
    Http::fake(['*/accounts*' => Http::response([
        'data' => [],
        'pagination' => ['hasMore' => true],
    ])]);

    expect(fn () => redbarkService()->listAccounts())
        ->toThrow(fn (RedbarkException $e) => expect($e->errorType)->toBe('truncated'));
});

test('listAccounts throws truncated on the X-Redbark-Truncated header rather than pruning on a partial list', function () {
    Http::fake(['*/accounts*' => Http::response(
        ['data' => redbarkRows(5), 'pagination' => ['hasMore' => false]],
        200,
        ['X-Redbark-Truncated' => 'true'],
    )]);

    expect(fn () => redbarkService()->listAccounts())
        ->toThrow(fn (RedbarkException $e) => expect($e->errorType)->toBe('truncated'));
});

test('getTransactions halves a truncated window and de-duplicates the overlap', function () {
    Http::fake(function (Request $request) {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return match ("{$query['from']}..{$query['to']}") {
            '2026-01-01..2026-01-31' => Http::response(
                ['data' => [['id' => 't1']], 'pagination' => ['hasMore' => false]],
                200,
                ['X-Redbark-Truncated' => 'true'],
            ),
            '2026-01-01..2026-01-16' => Http::response(
                ['data' => [['id' => 't1'], ['id' => 't2']], 'pagination' => ['hasMore' => false]],
            ),
            '2026-01-17..2026-01-31' => Http::response(
                ['data' => [['id' => 't2'], ['id' => 't3']], 'pagination' => ['hasMore' => false]],
            ),
            default => Http::response(['data' => [], 'pagination' => ['hasMore' => false]]),
        };
    });

    $transactions = redbarkService()->getTransactions(
        'conn_1',
        'acc_1',
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-01-31'),
    );

    expect(array_map(fn ($t): string => $t->id, $transactions))->toBe(['t1', 't2', 't3']);

    Http::assertSentCount(3);
});

test('getTransactions gives up with truncated when the window can no longer be narrowed', function () {
    Http::fake(['*/transactions*' => Http::response(
        ['data' => [['id' => 't1']], 'pagination' => ['hasMore' => false]],
        200,
        ['X-Redbark-Truncated' => 'true'],
    )]);

    $day = CarbonImmutable::parse('2026-01-05');

    expect(fn () => redbarkService()->getTransactions('conn_1', 'acc_1', $day, $day))
        ->toThrow(fn (RedbarkException $e) => expect($e->errorType)->toBe('truncated'));
});

test('getTransactions omits includePending unless it is requested', function () {
    Http::fake(['*/transactions*' => Http::response(['data' => [], 'pagination' => ['hasMore' => false]])]);

    $service = redbarkService();
    $from = CarbonImmutable::parse('2026-05-01');
    $to = CarbonImmutable::parse('2026-05-31');

    $service->getTransactions('conn_1', 'acc_1', $from, $to);

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'includePending'));

    $service->getTransactions('conn_1', 'acc_1', $from, $to, includePending: true);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'includePending=true'));
});

test('getBalances comma-joins the account ids and skips the call when there are none', function () {
    $fixture = redbarkFixture('balances');

    Http::fake(['*/balances*' => Http::response($fixture)]);

    expect(redbarkService()->getBalances([]))->toBe([]);

    Http::assertNothingSent();

    $ids = [$fixture['data'][0]['accountId'], $fixture['data'][1]['accountId']];
    $balances = redbarkService()->getBalances($ids);

    expect($balances)->toHaveCount(count($fixture['data']))
        ->and($balances[0]->accountId)->toBe($fixture['data'][0]['accountId'])
        ->and($balances[0]->currentBalance)->toBe($fixture['data'][0]['currentBalance'])
        ->and($balances[0]->currency)->toBe('AUD');

    Http::assertSent(fn (Request $request): bool => str_contains(
        urldecode($request->url()),
        'accountIds='.implode(',', $ids),
    ));
});

test('listConnections is unpaginated and sends no limit or offset', function () {
    $fixture = redbarkFixture('connections');

    // The live endpoint returns a bare {"data": [...]} with no pagination key at all.
    expect($fixture)->not->toHaveKey('pagination');

    Http::fake(['*/connections' => Http::response($fixture)]);

    $connections = redbarkService()->listConnections();

    expect($connections)->toHaveCount(count($fixture['data']))
        ->and($connections[0]->id)->toBe($fixture['data'][0]['id'])
        ->and($connections[0]->category)->toBe('banking')
        ->and($connections[0]->institutionName)->toBe($fixture['data'][0]['institutionName']);

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'limit')
        && ! str_contains($request->url(), 'offset'));
});

test('the captured transaction rows map with the sign as authoritative', function () {
    $fixture = redbarkFixture('transactions');

    Http::fake(['*/transactions*' => Http::response($fixture)]);

    $transactions = redbarkService()->getTransactions(
        'conn_1',
        'acc_1',
        CarbonImmutable::parse('2026-05-16'),
        CarbonImmutable::parse('2026-08-14'),
    );

    expect($transactions)->toHaveCount(count($fixture['data']));

    foreach ($transactions as $index => $transaction) {
        $row = $fixture['data'][$index];

        expect($transaction->id)->toBe($row['id'])
            ->and($transaction->amount)->toBe($row['amount'])
            ->and($transaction->status)->toBe('posted')
            // Every captured row agrees: negative is a debit, positive a credit.
            ->and($transaction->direction)->toBe((float) $row['amount'] < 0 ? 'debit' : 'credit');
    }
});

test('error statuses map to typed exceptions', function (int $status, string $exception, string $errorType) {
    Http::fake(['*/accounts*' => Http::response(['error' => ['message' => 'nope']], $status)]);

    expect(fn () => redbarkService()->listAccounts())
        ->toThrow(fn (RedbarkException $e) => expect($e)->toBeInstanceOf($exception)
            ->and($e->errorType)->toBe($errorType));
})->with([
    'bad request' => [400, RedbarkException::class, 'bad_request'],
    'unauthorized' => [401, RedbarkAuthenticationException::class, 'unauthorized'],
    'forbidden' => [403, RedbarkAuthenticationException::class, 'access_forbidden'],
    'not found' => [404, RedbarkException::class, 'not_found'],
    'gone' => [410, RedbarkException::class, 'bad_request'],
    'rate limited' => [429, RedbarkRateLimitException::class, 'rate_limited'],
    'server error' => [503, RedbarkServerException::class, 'server_error'],
    'teapot' => [418, RedbarkException::class, 'unknown'],
]);

test('a 429 response is retried and eventually succeeds', function () {
    Sleep::fake();

    Http::fake(['*/accounts*' => Http::sequence()
        ->push(['error' => ['message' => 'slow down']], 429)
        ->push(['data' => [], 'pagination' => ['hasMore' => false]])]);

    expect(redbarkService()->listAccounts())->toBe([]);

    Http::assertSentCount(2);
});

test('a 500 response is retried and eventually succeeds', function () {
    Sleep::fake();

    Http::fake(['*/accounts*' => Http::sequence()
        ->push(['error' => ['message' => 'boom']], 503)
        ->push(['data' => [], 'pagination' => ['hasMore' => false]])]);

    expect(redbarkService()->listAccounts())->toBe([]);

    Http::assertSentCount(2);
});

test('a failing status is retried by the client and exhausts into the typed exception', function () {
    Sleep::fake();

    Http::fake(['*/accounts*' => Http::response(['error' => ['message' => 'nope']], 503)]);

    expect(fn () => redbarkService()->listAccounts())->toThrow(RedbarkServerException::class);

    Http::assertSentCount(3);
});

test('a connection exception surfaces as a typed RedbarkException, not a raw framework exception', function () {
    Sleep::fake();

    Http::fake(['*/accounts*' => fn () => throw new ConnectionException('Connection refused')]);

    expect(fn () => redbarkService()->listAccounts())
        ->toThrow(fn (RedbarkException $e) => expect($e)->toBeInstanceOf(RedbarkRequestException::class)
            ->and($e->errorType)->toBe('connection_failed'));
});

test('the request carries the bearer token and asks for json', function () {
    Http::fake(['*/accounts*' => Http::response(['data' => [], 'pagination' => ['hasMore' => false]])]);

    redbarkService()->listAccounts();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer rbk_live_test')
        && $request->hasHeader('Accept', 'application/json'));
});
