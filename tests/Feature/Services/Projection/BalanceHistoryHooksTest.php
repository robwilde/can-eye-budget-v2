<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransferLinkSource;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\TransactionFeeFolder;
use App\Services\TransactionIngestor;
use App\Services\Transfers\TransferLinker;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo('2026-05-12');
    $this->user = User::factory()->create();
});

function hooksAccount(User $user, int $opening = 100000, bool $tracked = true): Account
{
    $account = Account::factory()->for($user)->create([
        'balance' => 0,
        'balance_source' => ImportSource::Manual,
        'is_tracked' => $tracked,
    ]);
    AccountValuation::factory()->for($account)->opening()->create(['valued_on' => '2026-05-01', 'balance' => $opening]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($account);

    return $account;
}

function hooksEnd(Account $account, string $date): int
{
    return $account->dailyBalances()->whereDate('balance_date', $date)->sole()->end_balance;
}

function hooksPlantSentinel(Account $account, string $from = '2026-05-01'): void
{
    $account->dailyBalances()->whereDate('balance_date', '>=', $from)->update(['end_balance' => 777]);
}

function hooksFeedRow(User $user, Account $account, int $cents, string $date): Transaction
{
    return Transaction::factory()->for($user)->fromRedbark()->create([
        'account_id' => $account->id,
        'direction' => $cents < 0 ? TransactionDirection::Debit : TransactionDirection::Credit,
        'amount' => $cents,
        'post_date' => $date,
        'description' => 'Moved to savings',
    ]);
}

test('rebuilds history after a transaction ingest', function () {
    $account = hooksAccount($this->user);

    app(TransactionIngestor::class)->ingest(new Transaction([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'amount' => -2000,
        'direction' => TransactionDirection::Debit,
        'description' => 'Bunnings',
        'post_date' => '2026-05-09',
        'status' => TransactionStatus::Posted,
        'source' => TransactionSource::Redbark,
    ]));

    expect(hooksEnd($account, '2026-05-08'))->toBe(100000)
        ->and(hooksEnd($account, '2026-05-09'))->toBe(98000)
        ->and(hooksEnd($account, '2026-05-12'))->toBe(98000);
});

test('leaves the history alone when a bulk caller defers the rebuild', function () {
    $account = hooksAccount($this->user);
    hooksPlantSentinel($account);

    app(TransactionIngestor::class)->ingest(new Transaction([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'amount' => 2000,
        'direction' => TransactionDirection::Debit,
        'description' => 'Bunnings',
        'post_date' => '2026-05-09',
        'status' => TransactionStatus::Posted,
        'source' => TransactionSource::Csv,
    ]), rebuildHistory: false);

    expect($account->dailyBalances()->pluck('end_balance')->unique()->values()->all())->toBe([777])
        ->and(Transaction::query()->where('account_id', $account->id)->count())->toBe(1);
});

test('counts a bank feed debit stored as negative cents as money out', function () {
    $account = hooksAccount($this->user);
    hooksFeedRow($this->user, $account, -2500, '2026-05-09');
    hooksFeedRow($this->user, $account, 4000, '2026-05-10');

    app(AccountBalanceHistoryBuilder::class)->rebuild($account);

    expect(hooksEnd($account, '2026-05-09'))->toBe(97500)
        ->and(hooksEnd($account, '2026-05-12'))->toBe(101500);
});

test('rebuilds history when a fee is folded into its purchase', function () {
    $account = hooksAccount($this->user);
    Transaction::factory()->for($this->user)->for($account)->create([
        'description' => 'VISA -JetBrains                Prague       CZ FRGN AMT-9.570000 051280 #8357',
        'amount' => 1394,
        'direction' => TransactionDirection::Debit,
        'post_date' => CarbonImmutable::parse('2026-05-09'),
        'planned_transaction_id' => null,
    ]);
    $fee = Transaction::factory()->for($this->user)->for($account)->create([
        'description' => 'Int Tran Fee - JetBrains              CZ - 951280',
        'amount' => 42,
        'direction' => TransactionDirection::Debit,
        'post_date' => CarbonImmutable::parse('2026-05-09'),
        'planned_transaction_id' => null,
    ]);
    hooksPlantSentinel($account, '2026-05-09');

    app(TransactionFeeFolder::class)->fold($fee);

    expect(hooksEnd($account, '2026-05-09'))->toBe(100000 - 1394 - 42)
        ->and(hooksEnd($account, '2026-05-12'))->toBe(100000 - 1394 - 42);
});

test('rebuilds the hidden account when a mirror leg is linked and unlinked', function () {
    $main = hooksAccount($this->user);
    $hidden = hooksAccount($this->user, 20000, tracked: false);
    $feed = hooksFeedRow($this->user, $main, -5000, '2026-05-10');

    $mirror = app(TransferLinker::class)->linkToUntrackedAccount($feed, $hidden, TransferLinkSource::Manual);

    expect(hooksEnd($hidden, '2026-05-09'))->toBe(20000)
        ->and(hooksEnd($hidden, '2026-05-12'))->toBe(25000);

    app(TransferLinker::class)->unlink($feed->fresh());

    expect(Transaction::query()->whereKey($mirror->id)->exists())->toBeFalse()
        ->and(hooksEnd($hidden, '2026-05-12'))->toBe(20000);
});

test('rebuilds the account when a mirror is replaced by a real row', function () {
    $main = hooksAccount($this->user);
    $hidden = hooksAccount($this->user, 20000, tracked: false);
    $feed = hooksFeedRow($this->user, $main, -5000, '2026-05-10');
    app(TransferLinker::class)->linkToUntrackedAccount($feed, $hidden, TransferLinkSource::Manual);
    hooksFeedRow($this->user, $hidden, 5000, '2026-05-10');
    $hidden->update(['is_tracked' => true]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($hidden);

    expect(hooksEnd($hidden, '2026-05-12'))->toBe(30000);

    $replaced = app(TransferLinker::class)->releaseMirrors($hidden->fresh());

    expect($replaced)->toBe(1)
        ->and(hooksEnd($hidden, '2026-05-12'))->toBe(25000);
});

test('rebuilds the parent account and date restored when a revision is deleted', function () {
    $first = hooksAccount($this->user);
    $second = hooksAccount($this->user);
    $original = Transaction::factory()->for($this->user)->for($first)->create([
        'amount' => 2000,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-05-03',
    ]);
    $revision = $original->createChild(['account_id' => $second->id, 'post_date' => '2026-05-11']);
    app(AccountBalanceHistoryBuilder::class)->rebuildForEdit($original, $revision);

    expect(hooksEnd($first, '2026-05-12'))->toBe(100000)
        ->and(hooksEnd($second, '2026-05-12'))->toBe(98000);

    $revision->delete();
    app(AccountBalanceHistoryBuilder::class)->rebuildForTransactions($revision);

    expect(hooksEnd($first, '2026-05-03'))->toBe(98000)
        ->and(hooksEnd($first, '2026-05-12'))->toBe(98000)
        ->and(hooksEnd($second, '2026-05-12'))->toBe(100000);
});

test('rebuilds once for every mirror replaced in one release', function () {
    $main = hooksAccount($this->user);
    $hidden = hooksAccount($this->user, 20000, tracked: false);
    $linker = app(TransferLinker::class);

    foreach ([[-5000, '2026-05-10'], [-3000, '2026-05-11']] as [$cents, $date]) {
        $feed = hooksFeedRow($this->user, $main, $cents, $date);
        $linker->linkToUntrackedAccount($feed, $hidden, TransferLinkSource::Manual);
        hooksFeedRow($this->user, $hidden, -$cents, $date);
    }

    $hidden->update(['is_tracked' => true]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($hidden);

    $rebuilds = 0;
    Transaction::query()->getConnection()->listen(function ($query) use (&$rebuilds): void {
        if (str_starts_with($query->sql, 'delete from "account_daily_balances"')) {
            $rebuilds++;
        }
    });

    $replaced = $linker->releaseMirrors($hidden->fresh());

    expect($replaced)->toBe(2)
        ->and($rebuilds)->toBe(1)
        ->and(hooksEnd($hidden, '2026-05-12'))->toBe(28000);
});

test('rebuilds only the accounts of the transactions it is given', function () {
    $mine = hooksAccount($this->user);
    $other = hooksAccount(User::factory()->create());
    hooksPlantSentinel($other);
    Transaction::factory()->for($this->user)->for($mine)->create([
        'amount' => 2000,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-05-09',
    ]);

    app(AccountBalanceHistoryBuilder::class)->rebuildForTransactions(Transaction::query()->where('account_id', $mine->id)->sole());

    expect(hooksEnd($mine, '2026-05-12'))->toBe(98000)
        ->and($other->dailyBalances()->pluck('end_balance')->unique()->values()->all())->toBe([777]);
});
