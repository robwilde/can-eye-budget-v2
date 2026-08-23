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
use Illuminate\Http\Client\Factory;
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
    // Http::fake() appends stubs and resolves on first match, so a second call would be
    // unreachable. Swap in a fresh factory so each call is the authoritative one and a
    // test can re-fake between two sync runs.
    Http::swap(new Factory);

    Http::fake([
        '*/connections*' => Http::response(['data' => $connections, 'pagination' => ['hasMore' => false]]),
        '*/accounts*' => Http::response(['data' => $accounts, 'pagination' => ['hasMore' => false]]),
        '*/transactions*' => Http::response(['data' => $transactions, 'pagination' => ['hasMore' => false]]),
        '*/balances*' => Http::response(['data' => $balances, 'pagination' => ['hasMore' => false]]),
    ]);
}

function runRedbarkSync(RedbarkFeed $feed, RefreshTrigger $trigger = RefreshTrigger::Manual): void
{
    new SyncRedbarkFeedJob($feed, $trigger)->handle(
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
        [],
        ['raw_transactions_payload' => [redbarkRow()], 'transactions_synced_at' => '2026-08-10 06:00:00'],
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
        // redbark_id alone marks feed ownership; the row is still the user's CSV import,
        // so a cleanup keyed on source can never mistake it for something the feed created.
        ->and($csv->source)->toBe(TransactionSource::Csv)
        // The user's richer description survives; the feed only adds what it knows.
        ->and($csv->description)->toBe('Direct Debit NIB - 64699390')
        ->and($csv->enrich_data['redbark']['category'])->toBe('Groceries');

    $log = RedbarkSyncLog::query()->latest('id')->firstOrFail();

    expect($log->transactions_created)->toBe(0)
        ->and($log->transactions_updated)->toBe(1);
});

test('a later sync does not overwrite an adopted row with the feed narration', function () {
    [$feed, , $account] = linkedRedbarkFeed();

    $csv = Transaction::factory()->create([
        'user_id' => $feed->user_id,
        'account_id' => $account->id,
        'amount' => -4250,
        'direction' => TransactionDirection::Debit,
        'source' => TransactionSource::Csv,
        'status' => TransactionStatus::Posted,
        'post_date' => '2026-08-10',
        'description' => 'Direct Debit NIB - 64699390',
        'redbark_id' => null,
    ]);

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [redbarkRow(['description' => 'Direct Debit NIB - xxxx9390'])],
    );

    runRedbarkSync($feed);

    RedbarkSyncLog::query()->update(['status' => RefreshStatus::Success]);

    // Second pass finds the row by redbark_id, which is the path that used to refill
    // every column from the payload and quietly undo the adoption.
    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [redbarkRow(['description' => 'Direct Debit NIB - xxxx9390'])],
    );

    runRedbarkSync($feed->fresh());

    $csv->refresh();

    expect(Transaction::query()->count())->toBe(1)
        ->and($csv->description)->toBe('Direct Debit NIB - 64699390')
        ->and($csv->source)->toBe(TransactionSource::Csv);
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

test('a single authentication failure counts the failure but leaves the feed usable', function () {
    [$feed] = linkedRedbarkFeed();

    Http::fake([
        '*/connections*' => Http::response(['data' => [], 'pagination' => ['hasMore' => false]]),
        '*/accounts*' => Http::response(['error' => ['message' => 'bad key']], 401),
    ]);

    expect(fn () => runRedbarkSync($feed))->toThrow(RedbarkAuthenticationException::class);

    expect($feed->fresh()->status)->toBe(RedbarkFeedStatus::Good)
        ->and($feed->fresh()->auth_failure_count)->toBe(1)
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

test('an uncleared authorisation is stored as pending, named after its merchant', function () {
    [$feed] = linkedRedbarkFeed();

    // Verbatim shape of a hold: placeholder narration, merchant in its own field, and
    // Redbark calling it posted anyway.
    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [redbarkRow([
            'id' => 'bank_tx_hold',
            'status' => 'posted',
            'description' => 'AUTHORISATION',
            'merchantName' => 'HARRIS FARM MARKETS PTY LWEST END     AU',
            'amount' => '-89.15',
        ])],
    );

    runRedbarkSync($feed);

    $transaction = Transaction::query()->sole();

    expect($transaction->status)->toBe(TransactionStatus::Pending)
        ->and($transaction->description)->toBe('HARRIS FARM MARKETS PTY LWEST END     AU')
        ->and($transaction->amount)->toBe(-8915);
});

test('a hold that clears is claimed by the settled row instead of duplicated', function () {
    $this->travelTo('2026-08-13 09:00:00');

    [$feed] = linkedRedbarkFeed();

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [redbarkRow([
            'id' => 'bank_tx_hold',
            'description' => 'AUTHORISATION',
            'merchantName' => 'HARRIS FARM MARKETS PTY L',
            'amount' => '-89.15',
            'date' => '2026-08-13',
        ])],
    );

    runRedbarkSync($feed);

    $hold = Transaction::query()->sole();

    expect($hold->status)->toBe(TransactionStatus::Pending);

    // Two days later the bank posts it: new content hash, real narration, no hold marker.
    $this->travelTo('2026-08-15 09:00:00');

    RedbarkSyncLog::query()->update(['status' => RefreshStatus::Success]);

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [redbarkRow([
            'id' => 'bank_tx_settled',
            'description' => 'VISA -HARRIS FARM MARKETS    WEST END     AU',
            'merchantName' => 'HARRIS FARM MARKETS PTY L',
            'amount' => '-89.15',
            'date' => '2026-08-15',
        ])],
    );

    runRedbarkSync($feed->fresh());

    $settled = Transaction::query()->sole();

    expect($settled->id)->toBe($hold->id)
        ->and($settled->redbark_id)->toBe('bank_tx_settled')
        ->and($settled->status)->toBe(TransactionStatus::Posted)
        ->and($settled->description)->toBe('VISA -HARRIS FARM MARKETS    WEST END     AU')
        // The hold's date is kept so the calendar does not jump.
        ->and($settled->post_date->toDateString())->toBe('2026-08-13');
});

test('a hold missing from the refetch is pruned from the snapshot', function () {
    [$feed, $redbarkAccount] = linkedRedbarkFeed(
        ['last_synced_at' => now()->subDay()],
        ['raw_transactions_payload' => [
            redbarkRow(['id' => 'bank_tx_hold', 'description' => 'AUTHORISATION', 'date' => now()->toDateString()]),
        ]],
    );

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [redbarkRow(['id' => 'bank_tx_other', 'date' => now()->toDateString()])],
    );

    runRedbarkSync($feed);

    expect(collect($redbarkAccount->fresh()->raw_transactions_payload)->pluck('id')->all())
        ->toBe(['bank_tx_other']);
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

    // A hold the feed created on an earlier run: it carries the id the bank has since
    // replaced, which is exactly why the redbark_id lookup misses it.
    $pending = Transaction::factory()->create([
        'user_id' => $feed->user_id,
        'account_id' => $account->id,
        'amount' => -4250,
        'direction' => TransactionDirection::Debit,
        'status' => TransactionStatus::Pending,
        'source' => TransactionSource::Redbark,
        'post_date' => '2026-08-12',
        'redbark_id' => 'rb_txn_hold',
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

test('a hold adopted from CSV history is still claimed when it settles', function () {
    $this->travelTo('2026-08-14 09:00:00');

    [$feed, , $account] = linkedRedbarkFeed();

    // Adoption leaves source alone, so the claim can only find this row by redbark_id.
    $adopted = Transaction::factory()->create([
        'user_id' => $feed->user_id,
        'account_id' => $account->id,
        'amount' => -4250,
        'direction' => TransactionDirection::Debit,
        'status' => TransactionStatus::Pending,
        'source' => TransactionSource::Csv,
        'post_date' => '2026-08-12',
        'redbark_id' => 'rb_txn_hold',
    ]);

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [redbarkRow(['id' => 'rb_txn_settled', 'date' => '2026-08-14'])],
    );

    runRedbarkSync($feed);

    $adopted->refresh();

    expect(Transaction::query()->count())->toBe(1)
        ->and($adopted->redbark_id)->toBe('rb_txn_settled')
        ->and($adopted->status)->toBe(TransactionStatus::Posted)
        ->and($adopted->source)->toBe(TransactionSource::Csv);
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

test('middleware expires the overlap lock after UNIQUE_FOR seconds, which exceeds the job timeout', function () {
    $feed = RedbarkFeed::factory()->create();
    $job = new SyncRedbarkFeedJob($feed);

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(Illuminate\Queue\Middleware\WithoutOverlapping::class)
        ->and($middleware[0]->expiresAfter)->toBe(SyncRedbarkFeedJob::UNIQUE_FOR)
        ->and(SyncRedbarkFeedJob::UNIQUE_FOR)->toBeGreaterThan($job->timeout);
});

test('the stranded pending sync log schedule closes only logs past the expiry window', function () {
    $feed = RedbarkFeed::factory()->create();

    $stranded = RedbarkSyncLog::factory()->create([
        'redbark_feed_id' => $feed->id,
        'user_id' => $feed->user_id,
        'status' => RefreshStatus::Pending,
        'created_at' => now()->subSeconds(SyncRedbarkFeedJob::UNIQUE_FOR + 61),
    ]);

    $fresh = RedbarkSyncLog::factory()->create([
        'redbark_feed_id' => $feed->id,
        'user_id' => $feed->user_id,
        'status' => RefreshStatus::Pending,
        'created_at' => now(),
    ]);

    $this->artisan('schedule:list')->assertSuccessful();

    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->sole(fn ($event): bool => $event->description === 'redbark:fail-stuck-sync-logs');

    $event->run(app());

    expect($stranded->fresh()->status)->toBe(RefreshStatus::Failed)
        ->and($fresh->fresh()->status)->toBe(RefreshStatus::Pending);
});

test('a per-account transaction fetch failure holds back only that account', function () {
    $this->travelTo('2026-08-14 09:00:00');

    $feed = RedbarkFeed::factory()->create();

    $redbarkAccounts = [];

    foreach (['rb_acc_1', 'rb_acc_2'] as $upstreamId) {
        $account = Account::factory()->for($feed->user)->create(['import_source' => ImportSource::Redbark]);

        $redbarkAccounts[$upstreamId] = RedbarkAccount::factory()->create([
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
        '*/transactions*' => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (($query['accountId'] ?? null) === 'rb_acc_2') {
                return Http::response(['error' => ['message' => 'server error']], 500);
            }

            // A non-empty snapshot is what makes transactionStartDate() take the
            // 7-day incremental branch on the next sync instead of a fresh backfill.
            return Http::response(['data' => [redbarkRow()], 'pagination' => ['hasMore' => false]]);
        },
        '*/balances*' => Http::response(['data' => [], 'pagination' => ['hasMore' => false]]),
    ]);

    runRedbarkSync($feed);

    expect($redbarkAccounts['rb_acc_1']->fresh()->transactions_synced_at)->not->toBeNull()
        ->and($redbarkAccounts['rb_acc_2']->fresh()->transactions_synced_at)->toBeNull()
        ->and($feed->fresh()->last_synced_at)->toBeNull();

    $log = RedbarkSyncLog::query()->latest('id')->firstOrFail();

    expect($log->status)->toBe(RefreshStatus::Failed)
        ->and(collect($log->errors)->pluck('context')->all())->toContain('transactions');

    Http::swap(new Factory);

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
        '*/balances*' => Http::response(['data' => [], 'pagination' => ['hasMore' => false]]),
    ]);

    runRedbarkSync($feed->fresh());

    $windows = collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/transactions'))
        ->mapWithKeys(function (array $pair): array {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return [$query['accountId'] => $query['from']];
        });

    // Account 1 has a non-null cursor and a non-empty snapshot from the first sync, so
    // it takes the 7-day incremental window; account 2 never got a successful fetch, so
    // it still takes the 90-day backfill. This is the actual per-account holdback
    // guarantee Step 2 exists for — not merely that the two windows differ.
    expect($windows['rb_acc_1'])->toBe('2026-08-07')
        ->and($windows['rb_acc_2'])->toBe('2026-05-16');
});

test('a synced redbark balance stamps the account balance source and timestamp', function () {
    [$feed, , $account] = linkedRedbarkFeed();

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        balances: [['accountId' => 'rb_acc_1', 'currentBalance' => '99.99']],
    );

    runRedbarkSync($feed);

    expect($account->fresh()->balance_source)->toBe(ImportSource::Redbark)
        ->and($account->fresh()->balance_updated_at)->not->toBeNull();
});

test('auth failures reaching the threshold flag the feed as needing a new key', function () {
    [$feed] = linkedRedbarkFeed(['auth_failure_count' => SyncRedbarkFeedJob::AUTH_FAILURE_THRESHOLD - 1]);

    Http::fake([
        '*/connections*' => Http::response(['data' => [], 'pagination' => ['hasMore' => false]]),
        '*/accounts*' => Http::response(['error' => ['message' => 'bad key']], 401),
    ]);

    expect(fn () => runRedbarkSync($feed))->toThrow(RedbarkAuthenticationException::class);

    expect($feed->fresh()->status)->toBe(RedbarkFeedStatus::RequiresUpdate)
        ->and($feed->fresh()->auth_failure_count)->toBe(SyncRedbarkFeedJob::AUTH_FAILURE_THRESHOLD);
});

test('a clean sync resets a prior authentication failure streak', function () {
    [$feed] = linkedRedbarkFeed(['auth_failure_count' => 2]);

    fakeRedbark(accounts: [redbarkUpstreamAccount()]);

    runRedbarkSync($feed);

    expect($feed->fresh()->auth_failure_count)->toBe(0);
});

test('backoff is exponential across three tiers', function () {
    $feed = RedbarkFeed::factory()->create();

    expect(new SyncRedbarkFeedJob($feed)->backoff())->toBe([60, 300, 900]);
});

test('a retry after a failed attempt authenticates with a fresh client using the current key', function () {
    [$feed] = linkedRedbarkFeed(['api_key' => 'old-key']);

    Http::fake([
        '*/connections*' => Http::response(['data' => [], 'pagination' => ['hasMore' => false]]),
        '*/accounts*' => Http::response(['error' => ['message' => 'bad key']], 401),
    ]);

    expect(fn () => runRedbarkSync($feed))->toThrow(RedbarkAuthenticationException::class);

    $firstAttemptAuth = collect(Http::recorded())->last()[0]->header('Authorization')[0];

    expect($firstAttemptAuth)->toBe('Bearer old-key');

    // A rotated key between retries is the only re-authentication seam Redbark's
    // static-key protocol offers: each attempt re-resolves the client from the feed.
    $feed->update(['api_key' => 'new-key']);

    fakeRedbark(accounts: [redbarkUpstreamAccount()]);

    runRedbarkSync($feed->fresh());

    $secondAttemptAuth = collect(Http::recorded())->last()[0]->header('Authorization')[0];

    expect($secondAttemptAuth)->toBe('Bearer new-key');
});
