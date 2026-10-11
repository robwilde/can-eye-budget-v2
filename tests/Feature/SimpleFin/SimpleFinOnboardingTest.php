<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Actions\Redbark\SaveRedbarkApiKey;
use App\Enums\RedbarkFeedStatus;
use App\Enums\RedbarkTransport;
use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Exceptions\Redbark\RedbarkAuthenticationException;
use App\Exceptions\Redbark\RedbarkRequestException;
use App\Jobs\SyncRedbarkFeedJob;
use App\Livewire\ConnectBank;
use App\Models\Account;
use App\Models\RedbarkAccount;
use App\Models\RedbarkFeed;
use App\Models\RedbarkSyncLog;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RedbarkClientFactory;
use App\Services\RedbarkService;
use App\Support\Redbark\InitialSyncWindow;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

uses()->group('simplefin');

const SIMPLEFIN_CLAIM_URL = 'https://api.redbark.com/simplefin/claim/sfin_setup_7f3a9c2e41d8';
const SIMPLEFIN_ACCESS_URL = 'https://sfuser:sfpass-9f8e7d@api.redbark.com/simplefin';
const SIMPLEFIN_PURPLE_ID = '6af4be01-a492-4f4b-93c9-da6dd1f93149';
const SIMPLEFIN_SAVER_ID = '9107ff81-0566-472b-b2fd-1f86588f38cd';

beforeEach(function () {
    Queue::fake();
    Sleep::fake();
    Http::preventStrayRequests();
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** @return array<string, mixed> */
function simpleFinPayload(bool $withPending = true): array
{
    $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/SimpleFin/accounts.json')), true, flags: JSON_THROW_ON_ERROR);

    if (! $withPending) {
        foreach ($payload['accounts'] as &$account) {
            $account['transactions'] = array_values(array_filter(
                $account['transactions'],
                static fn (array $transaction): bool => ($transaction['pending'] ?? false) !== true,
            ));
        }
    }

    return $payload;
}

function simpleFinToken(string $claimUrl = SIMPLEFIN_CLAIM_URL): string
{
    return base64_encode($claimUrl);
}

/** @param  array<string, mixed>|null  $payload */
function simpleFinFake(?array $payload = null, int $accountsStatus = 200): void
{
    Http::fake([
        '*/simplefin/claim/*' => Http::response(SIMPLEFIN_ACCESS_URL, 200, ['Content-Type' => 'text/plain']),
        '*/simplefin/accounts*' => Http::response($payload ?? simpleFinPayload(withPending: false), $accountsStatus),
    ]);
}

/** @param  array<string, mixed>  $stubs */
function simpleFinRefake(array $stubs): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake($stubs);
}

function simpleFinConnect(User $user): RedbarkFeed
{
    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->set('setup_token', simpleFinToken())
        ->call('connectSimpleFin')
        ->assertHasNoErrors();

    return RedbarkFeed::query()->where('user_id', $user->id)->sole();
}

function simpleFinSync(RedbarkFeed $feed): void
{
    app()->call([new SyncRedbarkFeedJob($feed, RefreshTrigger::Manual), 'handle']);
}

function simpleFinLink(RedbarkFeed $feed, string $upstreamId): Account
{
    $account = Account::factory()->for($feed->user)->create();

    RedbarkAccount::query()
        ->where('redbark_feed_id', $feed->id)
        ->where('redbark_account_id', $upstreamId)
        ->sole()
        ->update(['account_id' => $account->id]);

    return $account;
}

/** @return list<Request> */
function simpleFinRequests(string $path): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), $path))
        ->values()
        ->all();
}

/** @return array<string, string> */
function simpleFinQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

test('the onboarding connect step offers a SimpleFIN setup token field', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('connect-bank'))
        ->assertOk()
        ->assertSee('data-test="connect-bank-simplefin-token"', false);
});

test('a setup token is claimed once, the user moves on, and no secret is stored in the clear', function () {
    simpleFinFake();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->set('setup_token', simpleFinToken())
        ->call('connectSimpleFin')
        ->assertHasNoErrors()
        ->assertSet('step', ConnectBank::STEP_ACCOUNTS)
        ->assertSet('setup_token', '');

    $claims = simpleFinRequests('/simplefin/claim/');

    expect($claims)->toHaveCount(1)
        ->and($claims[0]->method())->toBe('POST')
        ->and($claims[0]->url())->toBe(SIMPLEFIN_CLAIM_URL);

    $stored = json_encode(DB::table('redbark_feeds')->where('user_id', $user->id)->sole(), JSON_THROW_ON_ERROR);

    expect($stored)->not->toContain('sfpass-9f8e7d')
        ->and($stored)->not->toContain('sfin_setup_7f3a9c2e41d8')
        ->and($stored)->not->toContain(simpleFinToken());

    Queue::assertPushed(SyncRedbarkFeedJob::class);
});

test('surrounding whitespace from a copy and paste does not spoil the token', function () {
    simpleFinFake();

    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->set('setup_token', "  \n".simpleFinToken()."\n ")
        ->call('connectSimpleFin')
        ->assertHasNoErrors()
        ->assertSet('step', ConnectBank::STEP_ACCOUNTS);
});

test('a token Redbark refuses keeps the user on step 1 with an inline error and stores nothing', function (int $status) {
    Http::fake(['*/simplefin/claim/*' => Http::response('', $status)]);

    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->set('setup_token', simpleFinToken())
        ->call('connectSimpleFin')
        ->assertHasErrors('setup_token')
        ->assertSet('step', ConnectBank::STEP_CONNECT)
        ->assertDontSee('try again');

    expect(RedbarkFeed::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([403, 404]);

test('a Redbark outage while claiming shows a retryable message and never retries the one-shot claim', function (int $status) {
    Http::fake(['*/simplefin/claim/*' => Http::response('', $status)]);

    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->set('setup_token', simpleFinToken())
        ->call('connectSimpleFin')
        ->assertHasErrors('setup_token')
        ->assertSet('step', ConnectBank::STEP_CONNECT)
        ->assertSee('try again');

    expect(simpleFinRequests('/simplefin/claim/'))->toHaveCount(1)
        ->and(RedbarkFeed::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([500, 503, 429]);

test('a dropped connection while claiming shows a retryable message and stores nothing', function () {
    Http::fake(['*/simplefin/claim/*' => Http::failedConnection()]);

    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->set('setup_token', simpleFinToken())
        ->call('connectSimpleFin')
        ->assertHasErrors('setup_token')
        ->assertSee('try again');

    expect(RedbarkFeed::query()->count())->toBe(0);
});

test('a token that is not a Redbark claim URL is rejected before any request leaves the server', function (string $token) {
    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->set('setup_token', $token)
        ->call('connectSimpleFin')
        ->assertHasErrors('setup_token')
        ->assertSet('step', ConnectBank::STEP_CONNECT);

    Http::assertNothingSent();
    expect(RedbarkFeed::query()->count())->toBe(0);
})->with([
    'not base64' => '%%%not-a-token%%%',
    'base64 of something that is not a URL' => base64_encode('hello world'),
    'another host' => base64_encode('https://evil.example.com/simplefin/claim/sfin_setup_7f3a9c2e41d8'),
    'a lookalike host' => base64_encode('https://api.redbark.com.evil.example/simplefin/claim/sfin_setup_7f3a9c2e41d8'),
    'plain http' => base64_encode('http://api.redbark.com/simplefin/claim/sfin_setup_7f3a9c2e41d8'),
    'a host smuggled in the userinfo' => base64_encode('https://api.redbark.com@evil.example/simplefin/claim/sfin_setup_7f3a9c2e41d8'),
    'dot segments leaving the claim path' => base64_encode('https://api.redbark.com/simplefin/claim/../../v1/accounts'),
]);

test('the first sync lists the accounts with their balances and authenticates with the access URL credentials', function () {
    simpleFinFake();
    $feed = simpleFinConnect(User::factory()->create());

    simpleFinSync($feed);

    $purple = RedbarkAccount::query()->where('redbark_account_id', SIMPLEFIN_PURPLE_ID)->sole();
    $saver = RedbarkAccount::query()->where('redbark_account_id', SIMPLEFIN_SAVER_ID)->sole();

    expect(RedbarkAccount::query()->count())->toBe(2)
        ->and($purple->name)->toContain('Purple Transactor Account')
        ->and($purple->account_number)->toBe('****5066')
        ->and($purple->currency)->toBe('AUD')
        ->and($purple->current_balance)->toBe(123456)
        ->and($saver->current_balance)->toBe(880010)
        ->and($feed->fresh()->pending_account_setup)->toBeTrue();

    $requests = simpleFinRequests('/simplefin/accounts');

    expect($requests)->not->toBeEmpty();

    foreach ($requests as $request) {
        expect($request->header('Authorization'))->toBe(['Basic '.base64_encode('sfuser:sfpass-9f8e7d')])
            ->and($request->url())->not->toContain('sfpass-9f8e7d');
    }
});

test('a linked account imports its posted transactions with signed amounts and the bank id', function () {
    simpleFinFake();
    $feed = simpleFinConnect(User::factory()->create());
    simpleFinSync($feed);
    $account = simpleFinLink($feed, SIMPLEFIN_PURPLE_ID);

    simpleFinSync($feed);

    $transactions = Transaction::query()->where('account_id', $account->id)->get()->keyBy('redbark_id');

    $rent = $transactions['bank_tx_409b6d0d1bd9cb1876bd1ddf13f86b6759e228e3bbdee67e2cde6f6b3e20381b'];
    $salary = $transactions['bank_tx_c1f0e2d3a4b5968778695a4b3c2d1e0f1a2b3c4d5e6f708192a3b4c5d6e7f8091'];

    expect($transactions)->toHaveCount(3)
        ->and($rent->user_id)->toBe($feed->user_id)
        ->and($rent->amount)->toBe(-77000)
        ->and($rent->direction)->toBe(TransactionDirection::Debit)
        ->and($rent->status)->toBe(TransactionStatus::Posted)
        ->and($rent->post_date->toDateString())->toBe('2026-08-14')
        ->and($salary->amount)->toBe(245000)
        ->and($salary->direction)->toBe(TransactionDirection::Credit)
        ->and($salary->description)->toContain('SALARY ACME')
        ->and(Transaction::query()->where('account_id', '!=', $account->id)->count())->toBe(0);

    expect(RedbarkAccount::query()->where('redbark_account_id', SIMPLEFIN_SAVER_ID)->sole()->account_id)->toBeNull();
});

test('syncing again does not duplicate transactions', function () {
    simpleFinFake();
    $feed = simpleFinConnect(User::factory()->create());
    simpleFinSync($feed);
    simpleFinLink($feed, SIMPLEFIN_PURPLE_ID);

    simpleFinSync($feed);
    simpleFinSync($feed);

    expect(Transaction::query()->count())->toBe(3);
});

test('the first import asks only for the linked account, from the start of last month', function () {
    Carbon::setTestNow('2026-08-20 10:00:00');
    CarbonImmutable::setTestNow('2026-08-20 10:00:00');
    simpleFinFake();
    $feed = simpleFinConnect(User::factory()->create());
    simpleFinSync($feed);
    simpleFinLink($feed, SIMPLEFIN_PURPLE_ID);

    simpleFinSync($feed);

    $expectedStart = (string) InitialSyncWindow::start(CarbonImmutable::now())->timestamp;

    $imports = collect(simpleFinRequests('/simplefin/accounts'))
        ->filter(fn (Request $request): bool => (simpleFinQuery($request)['start-date'] ?? null) === $expectedStart);

    expect($imports)->not->toBeEmpty();

    foreach ($imports as $request) {
        $query = (string) parse_url($request->url(), PHP_URL_QUERY);

        expect($query)->toMatch('/(?:^|&)account='.preg_quote(SIMPLEFIN_PURPLE_ID, '/').'(?:&|$)/')
            ->and($query)->not->toContain(SIMPLEFIN_SAVER_ID);
    }
});

test('pending transactions are only requested, and stored as pending, when the feed is set to include them', function () {
    config()->set('services.redbark.include_pending', true);
    simpleFinFake(simpleFinPayload(withPending: true));
    $feed = simpleFinConnect(User::factory()->create());
    simpleFinSync($feed);
    $account = simpleFinLink($feed, SIMPLEFIN_PURPLE_ID);

    simpleFinSync($feed);

    $pending = Transaction::query()
        ->where('account_id', $account->id)
        ->where('redbark_id', 'like', 'bank_tx_pending_%')
        ->sole();

    expect($pending->status)->toBe(TransactionStatus::Pending)
        ->and($pending->amount)->toBe(-1840)
        ->and($pending->direction)->toBe(TransactionDirection::Debit)
        ->and(collect(simpleFinRequests('/simplefin/accounts'))
            ->contains(fn (Request $request): bool => (simpleFinQuery($request)['pending'] ?? null) === '1'))->toBeTrue();
});

test('without the pending setting no request asks for pending transactions', function () {
    config()->set('services.redbark.include_pending', false);
    simpleFinFake();
    $feed = simpleFinConnect(User::factory()->create());
    simpleFinSync($feed);
    simpleFinLink($feed, SIMPLEFIN_PURPLE_ID);

    simpleFinSync($feed);

    expect(collect(simpleFinRequests('/simplefin/accounts'))
        ->contains(fn (Request $request): bool => array_key_exists('pending', simpleFinQuery($request))))->toBeFalse();
});

test('a bank connection that needs attention is reported without losing the accounts that did come back', function () {
    $payload = simpleFinPayload(withPending: false);
    $payload['errors'] = ['Connection to Beyond Bank Australia may need attention'];
    simpleFinFake($payload);
    $feed = simpleFinConnect(User::factory()->create());

    simpleFinSync($feed);

    $log = RedbarkSyncLog::query()->latest('id')->firstOrFail();

    expect(RedbarkAccount::query()->count())->toBe(2)
        ->and($log->status)->toBe(RefreshStatus::Success)
        ->and(json_encode($log->errors, JSON_THROW_ON_ERROR))->toContain('may need attention');
});

test('an unavailable account fails the run without deleting anything or marking the feed as synced', function () {
    simpleFinFake();
    $feed = simpleFinConnect(User::factory()->create());
    simpleFinSync($feed);
    simpleFinLink($feed, SIMPLEFIN_PURPLE_ID);
    simpleFinSync($feed);
    $syncedAt = $feed->fresh()->last_synced_at;

    Carbon::setTestNow(now()->addHours(6));
    CarbonImmutable::setTestNow(now());
    simpleFinRefake([
        '*/simplefin/accounts*' => Http::response([
            'errors' => ['simplefin_accounts_temporarily_unavailable'],
            'accounts' => [],
        ], 503),
    ]);

    simpleFinSync($feed);

    expect(Transaction::query()->count())->toBe(3)
        ->and(RedbarkAccount::query()->count())->toBe(2)
        ->and($feed->fresh()->last_synced_at?->equalTo($syncedAt))->toBeTrue()
        ->and(RedbarkSyncLog::query()->latest('id')->firstOrFail()->status)->toBe(RefreshStatus::Failed);
});

test('a revoked access URL counts as an authentication failure and flags the feed after repeated rejections', function () {
    simpleFinFake();
    $feed = simpleFinConnect(User::factory()->create());

    simpleFinRefake(['*/simplefin/accounts*' => Http::response('', 403)]);

    expect(fn () => simpleFinSync($feed))->toThrow(RedbarkAuthenticationException::class);
    expect($feed->fresh()->status)->toBe(RedbarkFeedStatus::Good)
        ->and($feed->fresh()->auth_failure_count)->toBe(1);

    for ($attempt = 1; $attempt < SyncRedbarkFeedJob::AUTH_FAILURE_THRESHOLD; $attempt++) {
        expect(fn () => simpleFinSync($feed->fresh()))->toThrow(RedbarkAuthenticationException::class);
    }

    expect($feed->fresh()->status)->toBe(RedbarkFeedStatus::RequiresUpdate);
});

test('saving an API key over a SimpleFIN feed switches it back to the REST client and drops the access URL', function () {
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->for($user)->create([
        'transport' => RedbarkTransport::SimpleFin,
        'api_key' => null,
        'simplefin_access_url' => SIMPLEFIN_ACCESS_URL,
    ]);

    app(SaveRedbarkApiKey::class)->handle($user, 'rbk_live_0123456789abcdef');

    $feed->refresh();

    expect($feed->transport)->toBe(RedbarkTransport::Rest)
        ->and($feed->api_key)->toBe('rbk_live_0123456789abcdef')
        ->and($feed->simplefin_access_url)->toBeNull()
        ->and(app(RedbarkClientFactory::class)->for($feed))->toBeInstanceOf(RedbarkService::class);
});

test('a SimpleFIN feed with linked accounts refuses an API key instead of orphaning them', function () {
    simpleFinFake();
    $user = User::factory()->create();
    $feed = simpleFinConnect($user);
    simpleFinSync($feed);
    simpleFinLink($feed, SIMPLEFIN_PURPLE_ID);

    expect(fn () => app(SaveRedbarkApiKey::class)->handle($user, 'rbk_live_0123456789abcdef'))
        ->toThrow(RedbarkRequestException::class);

    $feed->refresh();

    expect($feed->transport)->toBe(RedbarkTransport::SimpleFin)
        ->and($feed->simplefin_access_url)->toBe(SIMPLEFIN_ACCESS_URL)
        ->and($feed->accounts()->linked()->count())->toBe(1);
});

test('switching transport with nothing linked discards the old discovery state', function () {
    simpleFinFake();
    $user = User::factory()->create();
    $feed = RedbarkFeed::factory()->for($user)->create(['last_synced_at' => now(), 'pending_account_setup' => true]);
    RedbarkAccount::factory()->for($feed, 'feed')->create(['account_id' => null, 'redbark_account_id' => 'acct_old']);

    simpleFinConnect($user);

    $feed->refresh();

    expect($feed->last_synced_at)->toBeNull()
        ->and($feed->pending_account_setup)->toBeFalse()
        ->and(RedbarkAccount::query()->where('redbark_account_id', 'acct_old')->exists())->toBeFalse();
});

test('a claim that answers with a redirect is not followed', function () {
    Http::fake(['*/simplefin/claim/*' => Http::response('', 302, ['Location' => 'https://evil.example/collect'])]);

    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->set('setup_token', simpleFinToken())
        ->call('connectSimpleFin')
        ->assertHasErrors('setup_token');

    expect(Http::recorded())->toHaveCount(1)
        ->and(RedbarkFeed::query()->count())->toBe(0);
});

test('a skipped account survives a sync where its bank was unavailable', function () {
    simpleFinFake();
    $feed = simpleFinConnect(User::factory()->create());
    simpleFinSync($feed);
    RedbarkAccount::query()->where('redbark_account_id', SIMPLEFIN_SAVER_ID)->update(['ignored' => true]);

    $partial = simpleFinPayload(withPending: false);
    $partial['accounts'] = array_values(array_filter($partial['accounts'], static fn (array $account): bool => $account['id'] !== SIMPLEFIN_SAVER_ID));
    $partial['errors'] = ['Connection to Beyond Bank Australia may need attention'];
    simpleFinRefake(['*/simplefin/accounts*' => Http::response($partial)]);

    simpleFinSync($feed);

    expect(RedbarkAccount::query()->where('redbark_account_id', SIMPLEFIN_SAVER_ID)->value('ignored'))->toBeTrue();
});

test('a transactions read the provider could not serve fails the account fetch and holds the cursor', function () {
    simpleFinFake();
    $feed = simpleFinConnect(User::factory()->create());
    simpleFinSync($feed);
    simpleFinLink($feed, SIMPLEFIN_PURPLE_ID);
    simpleFinSync($feed);
    $cursor = RedbarkAccount::query()->where('redbark_account_id', SIMPLEFIN_PURPLE_ID)->value('transactions_synced_at');
    $syncedAt = $feed->fresh()->last_synced_at;

    Carbon::setTestNow(now()->addHours(6));
    CarbonImmutable::setTestNow(now());
    simpleFinRefake([
        '*/simplefin/accounts*' => static fn (Request $request) => str_contains($request->url(), 'account=')
            ? Http::response(['errors' => ['Transactions are temporarily withheld'], 'accounts' => []])
            : Http::response(simpleFinPayload(withPending: false)),
    ]);

    simpleFinSync($feed);

    $log = RedbarkSyncLog::query()->latest('id')->firstOrFail();

    expect($log->status)->toBe(RefreshStatus::Failed)
        ->and(RedbarkAccount::query()->where('redbark_account_id', SIMPLEFIN_PURPLE_ID)->value('transactions_synced_at'))->toEqual($cursor)
        ->and($feed->fresh()->last_synced_at?->equalTo($syncedAt))->toBeTrue();
});

test('a payment required answer counts as an authentication failure', function () {
    simpleFinFake();
    $feed = simpleFinConnect(User::factory()->create());

    simpleFinRefake(['*/simplefin/accounts*' => Http::response('', 402)]);

    expect(fn () => simpleFinSync($feed))->toThrow(RedbarkAuthenticationException::class)
        ->and($feed->fresh()->auth_failure_count)->toBe(1);
});

test('the payee becomes the merchant name of an imported transaction', function () {
    simpleFinFake();
    $feed = simpleFinConnect(User::factory()->create());
    simpleFinSync($feed);
    simpleFinLink($feed, SIMPLEFIN_PURPLE_ID);
    simpleFinSync($feed);

    expect(Transaction::query()->whereNotNull('merchant_name')->exists())->toBeTrue();
});

test('a provider notice with no accounts discovered fails the run so onboarding can explain it', function () {
    $payload = simpleFinPayload(withPending: false);
    $payload['accounts'] = [];
    $payload['errors'] = ['Connection to Beyond Bank Australia may need attention'];
    simpleFinFake($payload);
    $feed = simpleFinConnect(User::factory()->create());

    simpleFinSync($feed);

    $log = RedbarkSyncLog::query()->latest('id')->firstOrFail();

    expect($log->status)->toBe(RefreshStatus::Failed)
        ->and(json_encode($log->errors, JSON_THROW_ON_ERROR))->toContain('may need attention');
});
