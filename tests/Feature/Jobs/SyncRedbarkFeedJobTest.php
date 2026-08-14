<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Enums\RedbarkFeedStatus;
use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Events\TransactionEntered;
use App\Exceptions\Redbark\RedbarkAuthenticationException;
use App\Jobs\RunTransactionAnalysisJob;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\Account;
use App\Models\Category;
use App\Models\RedbarkAccount;
use App\Models\RedbarkFeed;
use App\Models\RedbarkSyncLog;
use App\Models\Transaction;
use App\Services\RedbarkClientFactory;
use App\Services\RedbarkTransactionMatcher;
use App\Services\TransactionIngestor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * @param  list<array<string, mixed>>  $accounts
 * @param  list<array<string, mixed>>  $connections
 * @param  list<array<string, mixed>>  $transactions
 * @param  list<array<string, mixed>>  $balances
 */
function fakeRedbark(
    array $accounts = [],
    array $connections = [],
    array $transactions = [],
    array $balances = [],
): void {
    Http::fake([
        '*/connections*' => Http::response(['data' => $connections, 'pagination' => ['hasMore' => false]]),
        '*/accounts*' => Http::response(['data' => $accounts, 'pagination' => ['hasMore' => false]]),
        '*/transactions*' => Http::response(['data' => $transactions, 'pagination' => ['hasMore' => false]]),
        '*/balances*' => Http::response(['data' => $balances, 'pagination' => ['hasMore' => false]]),
    ]);
}

function runRedbarkSync(RedbarkFeed $feed, RefreshTrigger $trigger = RefreshTrigger::Manual): void
{
    (new SyncRedbarkFeedJob($feed, $trigger))->handle(
        app(RedbarkClientFactory::class),
        app(TransactionIngestor::class),
        app(RedbarkTransactionMatcher::class),
    );
}

/** @param  array<string, mixed>  $overrides */
function redbarkRow(array $overrides = []): array
{
    return [
        'id' => 'rb_txn_1',
        'accountId' => 'rb_acc_1',
        'accountName' => 'Everyday Account',
        'status' => 'posted',
        'date' => '2026-08-10',
        'postDate' => '2026-08-10',
        'valueDate' => '2026-08-10',
        'description' => 'WOOLWORTHS 1234 BONDI',
        'amount' => '-42.50',
        'direction' => 'debit',
        'category' => 'Groceries',
        'merchantName' => 'Woolworths',
        'merchantCategoryCode' => '5411',
        ...$overrides,
    ];
}

/** @param  array<string, mixed>  $overrides */
function redbarkUpstreamAccount(array $overrides = []): array
{
    return [
        'id' => 'rb_acc_1',
        'connectionId' => 'rb_conn_1',
        'name' => 'Everyday Account',
        'type' => 'transaction',
        'institutionName' => 'Test Bank',
        'accountNumber' => '****4321',
        'currency' => 'AUD',
        ...$overrides,
    ];
}

/** @return array{0: RedbarkFeed, 1: RedbarkAccount, 2: Account} */
function linkedRedbarkFeed(array $feedOverrides = [], array $redbarkAccountOverrides = []): array
{
    $feed = RedbarkFeed::factory()->create($feedOverrides);
    $account = Account::factory()->for($feed->user)->create(['import_source' => ImportSource::Redbark]);

    $redbarkAccount = RedbarkAccount::factory()->create([
        'redbark_feed_id' => $feed->id,
        'account_id' => $account->id,
        'redbark_account_id' => 'rb_acc_1',
        'bank_connection_id' => 'rb_conn_1',
        'current_balance' => null,
        ...$redbarkAccountOverrides,
    ]);

    return [$feed, $redbarkAccount, $account];
}

function redbarkTransactionQuery(): array
{
    $query = [];

    foreach (Http::recorded() as [$request]) {
        if (str_contains($request->url(), '/transactions')) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        }
    }

    return $query;
}

beforeEach(function () {
    Queue::fake([RunTransactionAnalysisJob::class]);
});

test('a first sync backfills 90 days', function () {
    $this->travelTo('2026-08-14 09:00:00');

    [$feed] = linkedRedbarkFeed();

    fakeRedbark(accounts: [redbarkUpstreamAccount()]);

    runRedbarkSync($feed);

    expect(redbarkTransactionQuery()['from'])->toBe('2026-05-16');
});

test('a synced account only looks back 7 days', function () {
    $this->travelTo('2026-08-14 09:00:00');

    [$feed] = linkedRedbarkFeed(
        ['last_synced_at' => '2026-08-10 06:00:00'],
        ['raw_transactions_payload' => [redbarkRow()]],
    );

    fakeRedbark(accounts: [redbarkUpstreamAccount()]);

    runRedbarkSync($feed);

    expect(redbarkTransactionQuery()['from'])->toBe('2026-08-03');
});

test('sync_start_date governs the initial backfill even once the feed has synced', function () {
    $this->travelTo('2026-08-14 09:00:00');

    [$feed] = linkedRedbarkFeed(
        ['last_synced_at' => '2026-08-10 06:00:00'],
        ['raw_transactions_payload' => [], 'sync_start_date' => '2026-01-01'],
    );

    fakeRedbark(accounts: [redbarkUpstreamAccount()]);

    runRedbarkSync($feed);

    expect(redbarkTransactionQuery()['from'])->toBe('2026-01-01');
});

test('an existing CSV transaction is adopted, not duplicated', function () {
    [$feed, , $account] = linkedRedbarkFeed();

    // The same purchase the user already imported from a statement, with the fuller
    // narration the CSV carries and the masked one the feed sends.
    $csv = Transaction::factory()->create([
        'user_id' => $feed->user_id,
        'account_id' => $account->id,
        'amount' => -4250,
        'direction' => TransactionDirection::Debit,
        'source' => TransactionSource::Csv,
        'status' => TransactionStatus::Posted,
        'post_date' => '2026-08-10',
        'description' => 'Direct Debit NIB - 64699390',
        'category_id' => null,
        'redbark_id' => null,
    ]);

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [redbarkRow(['description' => 'Direct Debit NIB - xxxx9390'])],
    );

    runRedbarkSync($feed);

    $csv->refresh();

    expect(Transaction::query()->count())->toBe(1)
        ->and($csv->redbark_id)->toBe('rb_txn_1')
        ->and($csv->source)->toBe(TransactionSource::Redbark)
        // The user's richer description survives; the feed only adds what it knows.
        ->and($csv->description)->toBe('Direct Debit NIB - 64699390')
        ->and($csv->enrich_data['redbark']['category'])->toBe('Groceries');

    $log = RedbarkSyncLog::query()->latest('id')->firstOrFail();

    expect($log->transactions_created)->toBe(0)
        ->and($log->transactions_updated)->toBe(1);
});

test('an adopted transaction keeps the category the user set', function () {
    [$feed, , $account] = linkedRedbarkFeed();

    $category = Category::factory()->create();

    $csv = Transaction::factory()->create([
        'user_id' => $feed->user_id,
        'account_id' => $account->id,
        'amount' => -4250,
        'direction' => TransactionDirection::Debit,
        'source' => TransactionSource::Csv,
        'post_date' => '2026-08-10',
        'description' => 'WOOLWORTHS 1234 BONDI',
        'category_id' => $category->id,
        'redbark_id' => null,
    ]);

    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: [redbarkRow()]);

    runRedbarkSync($feed);

    expect($csv->fresh()->category_id)->toBe($category->id);
});

test('a folded fee is recognised and never resurrected', function () {
    [$feed, , $account] = linkedRedbarkFeed();

    $parent = Transaction::factory()->create([
        'user_id' => $feed->user_id,
        'account_id' => $account->id,
        'amount' => -10000,
        'source' => TransactionSource::Csv,
        'post_date' => '2026-08-10',
        'description' => 'VISA -JETBRAINS',
    ]);

    $fee = Transaction::factory()->create([
        'user_id' => $feed->user_id,
        'account_id' => $account->id,
        'amount' => -4250,
        'source' => TransactionSource::Csv,
        'post_date' => '2026-08-10',
        'description' => 'WOOLWORTHS 1234 BONDI',
        'folded_into_transaction_id' => $parent->id,
    ]);
    $fee->delete();

    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: [redbarkRow()]);

    runRedbarkSync($feed);

    expect(Transaction::query()->count())->toBe(1)
        ->and($fee->fresh()->trashed())->toBeTrue()
        ->and(RedbarkSyncLog::query()->latest('id')->firstOrFail()->transactions_created)->toBe(0);
});

test('two identical amounts on one day are matched one to one', function () {
    [$feed, , $account] = linkedRedbarkFeed();

    foreach (range(1, 2) as $i) {
        Transaction::factory()->create([
            'user_id' => $feed->user_id,
            'account_id' => $account->id,
            'amount' => -4250,
            'source' => TransactionSource::Csv,
            'post_date' => '2026-08-10',
            'description' => 'WOOLWORTHS 1234 BONDI',
            'redbark_id' => null,
        ]);
    }

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [redbarkRow(), redbarkRow(['id' => 'rb_txn_2'])],
    );

    runRedbarkSync($feed);

    expect(Transaction::query()->count())->toBe(2)
        ->and(Transaction::query()->whereNotNull('redbark_id')->count())->toBe(2)
        ->and(Transaction::query()->distinct()->count('redbark_id'))->toBe(2);
});

test('a genuinely new row is still created alongside adopted ones', function () {
    [$feed, , $account] = linkedRedbarkFeed();

    Transaction::factory()->create([
        'user_id' => $feed->user_id,
        'account_id' => $account->id,
        'amount' => -4250,
        'source' => TransactionSource::Csv,
        'post_date' => '2026-08-10',
        'description' => 'WOOLWORTHS 1234 BONDI',
        'redbark_id' => null,
    ]);

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [
            redbarkRow(),
            redbarkRow(['id' => 'rb_txn_new', 'description' => 'BUNNINGS WAREHOUSE', 'amount' => '-119.95']),
        ],
    );

    runRedbarkSync($feed);

    $log = RedbarkSyncLog::query()->latest('id')->firstOrFail();

    expect(Transaction::query()->count())->toBe(2)
        ->and($log->transactions_created)->toBe(1)
        ->and($log->transactions_updated)->toBe(1);
});

test('transactions are deduplicated by redbark id across runs', function () {
    [$feed] = linkedRedbarkFeed();

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [redbarkRow(), redbarkRow(['id' => 'rb_txn_2', 'amount' => '1200.00'])],
    );

    runRedbarkSync($feed);

    $first = RedbarkSyncLog::query()->latest('id')->firstOrFail();

    expect(Transaction::query()->count())->toBe(2)
        ->and($first->transactions_created)->toBe(2)
        ->and($first->transactions_updated)->toBe(0);

    $first->update(['status' => RefreshStatus::Success]);

    runRedbarkSync($feed->fresh());

    $second = RedbarkSyncLog::query()->latest('id')->firstOrFail();

    expect(Transaction::query()->count())->toBe(2)
        ->and($second->transactions_created)->toBe(0)
        ->and($second->transactions_updated)->toBe(2);
});

test('the CDR amount sign is preserved and drives the direction', function () {
    [$feed] = linkedRedbarkFeed();

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [
            redbarkRow(),
            redbarkRow(['id' => 'rb_txn_2', 'amount' => '1200.00', 'direction' => 'credit']),
        ],
    );

    runRedbarkSync($feed);

    $debit = Transaction::query()->where('redbark_id', 'rb_txn_1')->firstOrFail();
    $credit = Transaction::query()->where('redbark_id', 'rb_txn_2')->firstOrFail();

    expect($debit->amount)->toBe(-4250)
        ->and($debit->direction)->toBe(TransactionDirection::Debit)
        ->and($debit->source)->toBe(TransactionSource::Redbark)
        ->and($debit->merchant_name)->toBe('Woolworths')
        ->and($debit->enrich_data['redbark']['category'])->toBe('Groceries')
        ->and($credit->amount)->toBe(120000)
        ->and($credit->direction)->toBe(TransactionDirection::Credit);
});

test('the direction is derived from the sign even when the API contradicts it', function () {
    [$feed] = linkedRedbarkFeed();

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [redbarkRow(['direction' => 'credit'])],
    );

    runRedbarkSync($feed);

    $transaction = Transaction::query()->firstOrFail();

    expect($transaction->direction)->toBe(TransactionDirection::Debit)
        ->and($transaction->enrich_data['redbark']['direction'])->toBe('credit');
});

test('an unlinked account still gets a balance, so the setup wizard can show it', function () {
    $feed = RedbarkFeed::factory()->create();

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        balances: [['accountId' => 'rb_acc_1', 'currentBalance' => '4321.00', 'currency' => 'AUD']],
    );

    runRedbarkSync($feed);

    $redbarkAccount = RedbarkAccount::query()->sole();

    expect($redbarkAccount->account_id)->toBeNull()
        ->and($redbarkAccount->current_balance)->toBe(432100);
});

test('an ignored account is left out of the balances batch', function () {
    $feed = RedbarkFeed::factory()->create();

    RedbarkAccount::factory()->ignored()->create([
        'redbark_feed_id' => $feed->id,
        'redbark_account_id' => 'rb_acc_1',
        'current_balance' => null,
    ]);

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        balances: [['accountId' => 'rb_acc_1', 'currentBalance' => '4321.00']],
    );

    runRedbarkSync($feed);

    expect(RedbarkAccount::query()->sole()->current_balance)->toBeNull();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/balances'));
});

test('balances land on both the redbark account and the app account', function () {
    [$feed, $redbarkAccount, $account] = linkedRedbarkFeed();

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        balances: [['accountId' => 'rb_acc_1', 'currentBalance' => '1234.56', 'currency' => 'AUD']],
    );

    runRedbarkSync($feed);

    expect($redbarkAccount->fresh()->current_balance)->toBe(123456)
        ->and($account->fresh()->balance)->toBe(123456)
        ->and(RedbarkSyncLog::query()->latest('id')->firstOrFail()->balances_updated)->toBe(1);
});

test('a failed balances call never overwrites the previous balance', function () {
    [$feed, $redbarkAccount, $account] = linkedRedbarkFeed();

    $account->update(['balance' => 500000]);
    $redbarkAccount->update(['current_balance' => 500000]);

    Http::fake([
        '*/connections*' => Http::response(['data' => [], 'pagination' => ['hasMore' => false]]),
        '*/accounts*' => Http::response(['data' => [redbarkUpstreamAccount()], 'pagination' => ['hasMore' => false]]),
        '*/transactions*' => Http::response(['data' => [], 'pagination' => ['hasMore' => false]]),
        '*/balances*' => Http::response(['error' => ['message' => 'boom']], 500),
    ]);

    runRedbarkSync($feed);

    $log = RedbarkSyncLog::query()->latest('id')->firstOrFail();

    expect($account->fresh()->balance)->toBe(500000)
        ->and($redbarkAccount->fresh()->current_balance)->toBe(500000)
        ->and($log->status)->toBe(RefreshStatus::Failed)
        ->and(collect($log->errors)->pluck('context'))->toContain('balances');
});

test('a rejected balances batch retries one account at a time', function () {
    $feed = RedbarkFeed::factory()->create();

    foreach (['rb_acc_1', 'rb_acc_2'] as $upstreamId) {
        $account = Account::factory()->for($feed->user)->create(['import_source' => ImportSource::Redbark]);

        RedbarkAccount::factory()->create([
            'redbark_feed_id' => $feed->id,
            'account_id' => $account->id,
            'redbark_account_id' => $upstreamId,
            'bank_connection_id' => 'rb_conn_1',
            'current_balance' => null,
        ]);
    }

    Http::fake([
        '*/connections*' => Http::response(['data' => [], 'pagination' => ['hasMore' => false]]),
        '*/accounts*' => Http::response([
            'data' => [
                redbarkUpstreamAccount(),
                redbarkUpstreamAccount(['id' => 'rb_acc_2', 'name' => 'Savings']),
            ],
            'pagination' => ['hasMore' => false],
        ]),
        '*/transactions*' => Http::response(['data' => [], 'pagination' => ['hasMore' => false]]),
        '*/balances*' => Http::sequence()
            ->push(['error' => ['message' => 'unknown account in batch']], 400)
            ->push(['data' => [['accountId' => 'rb_acc_1', 'currentBalance' => '10.00']]])
            ->push(['data' => [['accountId' => 'rb_acc_2', 'currentBalance' => '20.00']]]),
    ]);

    runRedbarkSync($feed);

    $balances = RedbarkAccount::query()->pluck('current_balance', 'redbark_account_id');

    expect($balances['rb_acc_1'])->toBe(1000)
        ->and($balances['rb_acc_2'])->toBe(2000)
        ->and(RedbarkSyncLog::query()->latest('id')->firstOrFail()->balances_updated)->toBe(2);

    $balanceCalls = collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/balances'))
        ->count();

    expect($balanceCalls)->toBe(3);
});

test('an authentication failure marks the feed as needing a new key', function () {
    [$feed] = linkedRedbarkFeed();

    Http::fake([
        '*/connections*' => Http::response(['data' => [], 'pagination' => ['hasMore' => false]]),
        '*/accounts*' => Http::response(['error' => ['message' => 'bad key']], 401),
    ]);

    expect(fn () => runRedbarkSync($feed))->toThrow(RedbarkAuthenticationException::class);

    expect($feed->fresh()->status)->toBe(RedbarkFeedStatus::RequiresUpdate)
        ->and(RedbarkSyncLog::query()->latest('id')->firstOrFail()->status)->toBe(RefreshStatus::Failed);
});

test('an unlinked account that disappeared upstream is pruned', function () {
    $feed = RedbarkFeed::factory()->create();

    RedbarkAccount::factory()->create([
        'redbark_feed_id' => $feed->id,
        'account_id' => null,
        'redbark_account_id' => 'rb_acc_gone',
    ]);

    fakeRedbark(accounts: [redbarkUpstreamAccount()]);

    runRedbarkSync($feed);

    expect(RedbarkAccount::query()->pluck('redbark_account_id')->all())->toBe(['rb_acc_1']);
});

test('an empty upstream response prunes nothing', function () {
    $feed = RedbarkFeed::factory()->create();

    RedbarkAccount::factory()->create([
        'redbark_feed_id' => $feed->id,
        'account_id' => null,
        'redbark_account_id' => 'rb_acc_gone',
    ]);

    fakeRedbark();

    runRedbarkSync($feed);

    expect(RedbarkAccount::query()->count())->toBe(1);
});

test('a linked account is never pruned even when it vanishes upstream', function () {
    [$feed, $redbarkAccount] = linkedRedbarkFeed();

    fakeRedbark(accounts: [redbarkUpstreamAccount(['id' => 'rb_acc_other'])]);

    runRedbarkSync($feed);

    expect(RedbarkAccount::query()->whereKey($redbarkAccount->id)->exists())->toBeTrue();
});

test('a brokerage connection is skipped entirely', function () {
    $feed = RedbarkFeed::factory()->create();

    fakeRedbark(
        accounts: [redbarkUpstreamAccount(['connectionId' => 'rb_conn_broker'])],
        connections: [['id' => 'rb_conn_broker', 'category' => 'brokerage']],
    );

    runRedbarkSync($feed);

    expect(RedbarkAccount::query()->count())->toBe(0);
});

test('a stored pending row that vanished from the refetch is dropped from the snapshot', function () {
    [$feed, $redbarkAccount] = linkedRedbarkFeed(
        ['last_synced_at' => now()->subDay()],
        ['raw_transactions_payload' => [
            redbarkRow(['id' => 'rb_txn_pending', 'status' => 'pending', 'date' => now()->toDateString()]),
            redbarkRow(['id' => 'rb_txn_kept', 'status' => 'posted', 'date' => now()->toDateString()]),
        ]],
    );

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [redbarkRow(['id' => 'rb_txn_fresh', 'date' => now()->toDateString()])],
    );

    runRedbarkSync($feed);

    $ids = collect($redbarkAccount->fresh()->raw_transactions_payload)->pluck('id')->all();

    expect($ids)->toContain('rb_txn_fresh')
        ->and($ids)->toContain('rb_txn_kept')
        ->and($ids)->not->toContain('rb_txn_pending');
});

test('an empty transactions response leaves the stored snapshot alone', function () {
    [$feed, $redbarkAccount] = linkedRedbarkFeed(
        ['last_synced_at' => now()->subDay()],
        ['raw_transactions_payload' => [
            redbarkRow(['id' => 'rb_txn_pending', 'status' => 'pending', 'date' => now()->toDateString()]),
        ]],
    );

    fakeRedbark(accounts: [redbarkUpstreamAccount()]);

    runRedbarkSync($feed);

    expect($redbarkAccount->fresh()->raw_transactions_payload)->toHaveCount(1);
});

test('a settled pending transaction is claimed instead of duplicated when the bank reissues the id', function () {
    $this->travelTo('2026-08-14 09:00:00');

    [$feed, $redbarkAccount, $account] = linkedRedbarkFeed();

    $pending = Transaction::factory()->create([
        'user_id' => $feed->user_id,
        'account_id' => $account->id,
        'amount' => -4250,
        'direction' => TransactionDirection::Debit,
        'status' => TransactionStatus::Pending,
        'source' => TransactionSource::Redbark,
        'post_date' => '2026-08-12',
        'redbark_id' => null,
    ]);

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [redbarkRow(['id' => 'rb_txn_settled', 'date' => '2026-08-14'])],
    );

    runRedbarkSync($feed);

    $pending->refresh();

    expect(Transaction::query()->count())->toBe(1)
        ->and($pending->redbark_id)->toBe('rb_txn_settled')
        ->and($pending->status)->toBe(TransactionStatus::Posted)
        ->and($pending->post_date->toDateString())->toBe('2026-08-12')
        ->and(RedbarkSyncLog::query()->latest('id')->firstOrFail()->transactions_updated)->toBe(1);
});

test('new transactions go through the ingestor', function () {
    Event::fake([TransactionEntered::class]);

    [$feed] = linkedRedbarkFeed();

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [redbarkRow(), redbarkRow(['id' => 'rb_txn_2'])],
    );

    runRedbarkSync($feed);

    Event::assertDispatchedTimes(TransactionEntered::class, 2);
});

test('the analysis pipeline is dispatched for the feed owner', function () {
    [$feed] = linkedRedbarkFeed();

    fakeRedbark(accounts: [redbarkUpstreamAccount()]);

    runRedbarkSync($feed);

    Queue::assertPushed(
        RunTransactionAnalysisJob::class,
        fn (RunTransactionAnalysisJob $job): bool => $job->user->is($feed->user),
    );
});

test('a sync flags accounts that still need setup', function () {
    $feed = RedbarkFeed::factory()->create();

    fakeRedbark(accounts: [redbarkUpstreamAccount()]);

    runRedbarkSync($feed);

    expect($feed->fresh()->pending_account_setup)->toBeTrue()
        ->and(RedbarkAccount::query()->firstOrFail()->name)->toBe('Test Bank - Everyday Account');
});

test('an ignored account is neither fetched nor flagged for setup', function () {
    $feed = RedbarkFeed::factory()->create();

    RedbarkAccount::factory()->ignored()->create([
        'redbark_feed_id' => $feed->id,
        'redbark_account_id' => 'rb_acc_1',
    ]);

    fakeRedbark(accounts: [redbarkUpstreamAccount()]);

    runRedbarkSync($feed);

    expect($feed->fresh()->pending_account_setup)->toBeFalse();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/transactions'));
});

test('a successful run records the cursor and a clean log', function () {
    [$feed] = linkedRedbarkFeed();

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        connections: [['id' => 'rb_conn_1', 'category' => 'banking', 'institutionName' => 'Test Bank']],
        transactions: [redbarkRow()],
        balances: [['accountId' => 'rb_acc_1', 'currentBalance' => '99.99']],
    );

    runRedbarkSync($feed, RefreshTrigger::Scheduled);

    $log = RedbarkSyncLog::query()->latest('id')->firstOrFail();

    expect($feed->fresh()->last_synced_at)->not->toBeNull()
        ->and($log->status)->toBe(RefreshStatus::Success)
        ->and($log->trigger)->toBe(RefreshTrigger::Scheduled)
        ->and($log->errors)->toBeNull()
        ->and($log->accounts_synced)->toBe(1)
        ->and($log->transactions_created)->toBe(1)
        ->and($log->balances_updated)->toBe(1);
});
