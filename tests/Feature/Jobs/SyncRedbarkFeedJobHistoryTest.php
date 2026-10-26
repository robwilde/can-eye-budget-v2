<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Enums\RefreshTrigger;
use App\Enums\TransactionDirection;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\RedbarkClientFactory;
use App\Services\RedbarkTransactionMatcher;
use App\Services\TransactionIngestor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Queue::fake();
    $this->travelTo('2026-08-14 09:00:00');
});

function historySyncRun(object $feed): void
{
    new SyncRedbarkFeedJob($feed, RefreshTrigger::Manual)->handle(
        app(RedbarkClientFactory::class),
        app(TransactionIngestor::class),
        app(RedbarkTransactionMatcher::class),
    );
}

/** @param  array<string, mixed>  $overrides */
function historySyncRow(array $overrides = []): array
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
        'merchantName' => 'Woolworths',
        ...$overrides,
    ];
}

function historySyncOpening(Account $account, int $balance): void
{
    AccountValuation::factory()->for($account)->opening()->create(['valued_on' => '2026-08-01', 'balance' => $balance]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($account);
}

function historySyncEnd(Account $account, string $date): int
{
    return $account->dailyBalances()->whereDate('balance_date', $date)->sole()->end_balance;
}

test('rebuilds history after a sync imports transactions', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    historySyncOpening($account, 100000);

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [
            historySyncRow(['id' => 'rb_txn_1', 'date' => '2026-08-10', 'postDate' => '2026-08-10', 'amount' => '-42.50']),
            historySyncRow(['id' => 'rb_txn_2', 'date' => '2026-08-12', 'postDate' => '2026-08-12', 'amount' => '100.00']),
        ],
    );

    historySyncRun($feed);

    expect(historySyncEnd($account, '2026-08-09'))->toBe(100000)
        ->and(historySyncEnd($account, '2026-08-10'))->toBe(95750)
        ->and(historySyncEnd($account, '2026-08-14'))->toBe(105750);
});

test('rebuilds history when the synced balance changes', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    $account->update(['balance' => 0]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($account);

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        balances: [['accountId' => 'rb_acc_1', 'currentBalance' => '1234.56', 'currency' => 'AUD']],
    );

    historySyncRun($feed);

    expect(historySyncEnd($account, '2026-08-14'))->toBe(123456);
});

test('rebuilds only the synced users account history', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    historySyncOpening($account, 100000);

    $bystander = Account::factory()->for(User::factory()->create())->create(['import_source' => ImportSource::Redbark]);
    historySyncOpening($bystander, 50000);
    $bystander->dailyBalances()->update(['end_balance' => 777]);

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [historySyncRow(['date' => '2026-08-10', 'postDate' => '2026-08-10'])],
    );

    historySyncRun($feed);

    expect(historySyncEnd($account, '2026-08-14'))->toBe(95750)
        ->and($bystander->dailyBalances()->pluck('end_balance')->unique()->values()->all())->toBe([777]);
});

test('rebuilds from where an updated row used to sit when the sync moves it later', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    AccountValuation::factory()->for($account)->opening()->create(['valued_on' => '2026-07-20', 'balance' => 100000]);
    Transaction::factory()->for($feed->user)->for($account)->fromRedbark()->create([
        'redbark_id' => 'rb_txn_1',
        'direction' => TransactionDirection::Debit,
        'amount' => 5000,
        'post_date' => '2026-08-01',
    ]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($account);

    expect(historySyncEnd($account, '2026-08-05'))->toBe(95000);

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [historySyncRow(['id' => 'rb_txn_1', 'date' => '2026-08-12', 'postDate' => '2026-08-12', 'amount' => '-50.00'])],
    );

    historySyncRun($feed);

    expect(historySyncEnd($account, '2026-08-05'))->toBe(100000)
        ->and(historySyncEnd($account, '2026-08-11'))->toBe(100000)
        ->and(historySyncEnd($account, '2026-08-12'))->toBe(95000)
        ->and(historySyncEnd($account, '2026-08-14'))->toBe(95000);
});

test('commits the synced balance and rows together with the rebuilt history', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    $account->update(['balance' => 0]);
    Schema::drop('account_daily_balances');

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        balances: [['accountId' => 'rb_acc_1', 'currentBalance' => '1234.56', 'currency' => 'AUD']],
        transactions: [historySyncRow(['date' => '2026-08-10', 'postDate' => '2026-08-10'])],
    );

    expect(fn () => historySyncRun($feed))->toThrow(QueryException::class)
        ->and($account->fresh()->balance)->toBe(0)
        ->and(Transaction::query()->where('account_id', $account->id)->count())->toBe(0);
});

test('rebuilds from the held row date when a pending row settles under a new id', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    historySyncOpening($account, 100000);
    Transaction::factory()->for($feed->user)->for($account)->fromRedbark()->pending()->create([
        'redbark_id' => 'rb_hold',
        'direction' => TransactionDirection::Debit,
        'amount' => -4250,
        'post_date' => '2026-08-05',
    ]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($account);

    expect(historySyncEnd($account, '2026-08-09'))->toBe(100000);

    fakeRedbark(
        accounts: [redbarkUpstreamAccount()],
        transactions: [historySyncRow(['id' => 'rb_settled', 'date' => '2026-08-10', 'postDate' => '2026-08-10', 'amount' => '-42.50'])],
    );

    historySyncRun($feed);

    expect(historySyncEnd($account, '2026-08-04'))->toBe(100000)
        ->and(historySyncEnd($account, '2026-08-05'))->toBe(95750)
        ->and(historySyncEnd($account, '2026-08-09'))->toBe(95750)
        ->and(historySyncEnd($account, '2026-08-14'))->toBe(95750);
});
