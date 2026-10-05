<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\ContextDevServiceContract;
use App\DTOs\MerchantBrandData;
use App\Enums\CleanDescriptionSource;
use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Jobs\EnrichMerchantBrandsJob;
use App\Jobs\RunTransactionAnalysisJob;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\RedbarkFeed;
use App\Models\RedbarkSyncLog;
use App\Models\Transaction;
use App\Services\RedbarkClientFactory;
use App\Services\RedbarkTransactionMatcher;
use App\Services\TransactionIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function syncForNames(RedbarkFeed $feed): void
{
    new SyncRedbarkFeedJob($feed, RefreshTrigger::Manual)->handle(
        app(RedbarkClientFactory::class),
        app(TransactionIngestor::class),
        app(RedbarkTransactionMatcher::class),
    );

    RedbarkSyncLog::query()->update(['status' => RefreshStatus::Success]);
}

/** @param  array<string, mixed>  $overrides */
function namedRedbarkRow(array $overrides = []): array
{
    return [
        'id' => 'rb_txn_names',
        'accountId' => 'rb_acc_1',
        'status' => 'posted',
        'date' => '2026-08-10',
        'postDate' => '2026-08-10',
        'description' => 'WOOLWORTHS 1234 BONDI',
        'amount' => '-42.50',
        'direction' => 'debit',
        'merchantName' => 'Woolworths',
        ...$overrides,
    ];
}

/** @param  list<array<string, mixed>>  $rows */
function fakeNamedRows(array $rows): void
{
    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: $rows);
}

beforeEach(function () {
    Queue::fake([RunTransactionAnalysisJob::class, EnrichMerchantBrandsJob::class]);
});

test('a first sync names each row after the merchant Redbark sent', function () {
    [$feed] = linkedRedbarkFeed();
    fakeNamedRows([namedRedbarkRow(['merchantName' => '  Woolworths   Bondi '])]);

    syncForNames($feed);

    $transaction = Transaction::query()->sole();

    expect($transaction->clean_description)->toBe('Woolworths Bondi')
        ->and($transaction->clean_description_source)->toBe(CleanDescriptionSource::Feed)
        ->and($transaction->description)->toBe('WOOLWORTHS 1234 BONDI');
});

test('a row without a merchant name falls back to the normalised narration', function () {
    [$feed] = linkedRedbarkFeed();
    fakeNamedRows([namedRedbarkRow(['description' => 'VISA WOOLWORTHS 1234 BONDI', 'merchantName' => null])]);

    syncForNames($feed);

    $transaction = Transaction::query()->sole();

    expect($transaction->clean_description)->toBe('Woolworths Bondi')
        ->and($transaction->clean_description_source)->toBe(CleanDescriptionSource::Derived)
        ->and($transaction->description)->toBe('VISA WOOLWORTHS 1234 BONDI');
});

test('the merchant name outranks the normalised narration', function () {
    [$feed] = linkedRedbarkFeed();
    fakeNamedRows([namedRedbarkRow(['description' => 'VISA WOOLWORTHS 1234 BONDI', 'merchantName' => 'Woolies'])]);

    syncForNames($feed);

    expect(Transaction::query()->sole()->clean_description)->toBe('Woolies');
});

test('a placeholder narration with no merchant is left unnamed', function () {
    [$feed] = linkedRedbarkFeed();
    fakeNamedRows([namedRedbarkRow(['description' => 'AUTHORISATION', 'merchantName' => null])]);

    syncForNames($feed);

    $transaction = Transaction::query()->sole();

    expect($transaction->clean_description)->toBeNull()
        ->and($transaction->clean_description_source)->toBeNull();
});

test('a later sync never overwrites a name a person or a rule chose', function (CleanDescriptionSource $source) {
    [$feed] = linkedRedbarkFeed();
    fakeNamedRows([namedRedbarkRow()]);
    syncForNames($feed);

    Transaction::query()->sole()->update([
        'clean_description' => 'My own name',
        'clean_description_source' => $source,
    ]);

    fakeNamedRows([namedRedbarkRow(['merchantName' => 'Woolworths Metro'])]);
    syncForNames($feed->fresh());

    $transaction = Transaction::query()->sole();

    expect($transaction->clean_description)->toBe('My own name')
        ->and($transaction->clean_description_source)->toBe($source);
})->with([
    'a person' => [CleanDescriptionSource::Manual],
    'a rule' => [CleanDescriptionSource::Rule],
]);

test('a later sync refreshes a name the feed itself chose', function () {
    [$feed] = linkedRedbarkFeed();
    fakeNamedRows([namedRedbarkRow()]);
    syncForNames($feed);

    fakeNamedRows([namedRedbarkRow(['merchantName' => 'Woolworths Metro'])]);
    syncForNames($feed->fresh());

    $transaction = Transaction::query()->sole();

    expect($transaction->clean_description)->toBe('Woolworths Metro')
        ->and($transaction->clean_description_source)->toBe(CleanDescriptionSource::Feed);
});

test('an adopted row keeps the name its owner gave it and a blank one is filled', function () {
    [$feed, , $account] = linkedRedbarkFeed();

    $named = Transaction::factory()->create([
        'user_id' => $feed->user_id,
        'account_id' => $account->id,
        'amount' => -4250,
        'direction' => TransactionDirection::Debit,
        'source' => TransactionSource::Csv,
        'status' => TransactionStatus::Posted,
        'post_date' => '2026-08-10',
        'description' => 'Direct Debit NIB - 64699390',
        'clean_description' => 'Health insurance',
        'redbark_id' => null,
    ]);

    $blank = Transaction::factory()->create([
        'user_id' => $feed->user_id,
        'account_id' => $account->id,
        'amount' => -1999,
        'direction' => TransactionDirection::Debit,
        'source' => TransactionSource::Csv,
        'status' => TransactionStatus::Posted,
        'post_date' => '2026-08-11',
        'description' => 'NETFLIX.COM',
        'clean_description' => null,
        'redbark_id' => null,
    ]);

    fakeNamedRows([
        namedRedbarkRow(['description' => 'Direct Debit NIB - xxxx9390', 'merchantName' => 'NIB']),
        namedRedbarkRow(['id' => 'rb_txn_netflix', 'date' => '2026-08-11', 'postDate' => '2026-08-11', 'description' => 'NETFLIX.COM', 'amount' => '-19.99', 'merchantName' => 'Netflix']),
    ]);

    syncForNames($feed);

    expect(Transaction::query()->count())->toBe(2)
        ->and($named->fresh()->clean_description)->toBe('Health insurance')
        ->and($named->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Manual)
        ->and($blank->fresh()->clean_description)->toBe('Netflix')
        ->and($blank->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Feed);
});

test('the first sync queues merchant enrichment for the feed owner', function () {
    [$feed] = linkedRedbarkFeed();
    fakeNamedRows([namedRedbarkRow()]);

    syncForNames($feed);

    Queue::assertPushed(EnrichMerchantBrandsJob::class, fn (EnrichMerchantBrandsJob $job): bool => $job->user->is($feed->user));
});

test('a resync refreshes the current revision after a notes-only edit and keeps a manual rename', function () {
    [$feed] = linkedRedbarkFeed();
    fakeNamedRows([namedRedbarkRow(['id' => 'rb_txn_a']), namedRedbarkRow(['id' => 'rb_txn_b', 'date' => '2026-08-11', 'postDate' => '2026-08-11', 'amount' => '-9.00', 'merchantName' => 'Coles'])]);
    syncForNames($feed);

    $a = Transaction::query()->where('redbark_id', 'rb_txn_a')->sole();
    $b = Transaction::query()->where('redbark_id', 'rb_txn_b')->sole();
    $childA = $a->createChild(['notes' => 'Weekly shop']);
    $childB = $b->createChild(['clean_description' => 'My Coles', 'clean_description_source' => CleanDescriptionSource::Manual->value]);

    fakeNamedRows([
        namedRedbarkRow(['id' => 'rb_txn_a', 'merchantName' => 'Woolworths Metro']),
        namedRedbarkRow(['id' => 'rb_txn_b', 'date' => '2026-08-11', 'postDate' => '2026-08-11', 'amount' => '-9.00', 'merchantName' => 'Coles Express']),
    ]);
    syncForNames($feed->fresh());

    expect($childA->fresh()->clean_description)->toBe('Woolworths Metro')
        ->and($childA->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Feed)
        ->and($childA->fresh()->notes)->toBe('Weekly shop')
        ->and($childB->fresh()->clean_description)->toBe('My Coles')
        ->and($childB->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Manual);
});

test('a reference-only narration with no merchant is left unnamed', function () {
    [$feed] = linkedRedbarkFeed();
    fakeNamedRows([namedRedbarkRow(['description' => 'Ref#884905699 2422732337', 'merchantName' => null])]);

    syncForNames($feed);

    $transaction = Transaction::query()->sole();

    expect($transaction->clean_description)->toBeNull()
        ->and($transaction->clean_description_source)->toBeNull();
});

test('a first sync with enrichment on names unnamed rows from a brand within the credit budget', function (int $cap, int $lookups, string $expected, CleanDescriptionSource $source) {
    config(['services.context_dev.enrichment_enabled' => true, 'services.context_dev.daily_credit_cap' => $cap]);
    Queue::fake([RunTransactionAnalysisJob::class]);
    $this->travelTo(CarbonImmutable::parse('2026-08-12'));

    $contextDev = Mockery::mock(ContextDevServiceContract::class);
    $contextDev->shouldReceive('brandFromTransaction')->times($lookups)
        ->andReturn(new MerchantBrandData(title: 'Woolworths Group'));
    app()->instance(ContextDevServiceContract::class, $contextDev);

    [$feed] = linkedRedbarkFeed();
    fakeNamedRows([
        namedRedbarkRow(['id' => 'rb_txn_1', 'description' => 'VISA WOOLWORTHS 1234 BONDI', 'merchantName' => null]),
        namedRedbarkRow(['id' => 'rb_txn_2', 'date' => '2026-08-11', 'postDate' => '2026-08-11', 'description' => 'VISA WOOLWORTHS 5678 BONDI', 'merchantName' => null]),
    ]);

    syncForNames($feed);

    expect(Transaction::query()->pluck('clean_description')->unique()->all())->toBe([$expected])
        ->and(Transaction::query()->pluck('clean_description_source')->unique()->all())->toBe([$source]);
})->with([
    'budget for one lookup' => [10, 1, 'Woolworths Group', CleanDescriptionSource::Brand],
    'budget spent' => [0, 0, 'Woolworths Bondi', CleanDescriptionSource::Derived],
]);

test('a settled hold names its current revision after a notes-only edit', function () {
    $this->travelTo('2026-08-14 09:00:00');

    [$feed, , $account] = linkedRedbarkFeed();

    $hold = Transaction::factory()->create([
        'user_id' => $feed->user_id,
        'account_id' => $account->id,
        'amount' => -4250,
        'direction' => TransactionDirection::Debit,
        'status' => TransactionStatus::Pending,
        'source' => TransactionSource::Redbark,
        'post_date' => '2026-08-12',
        'redbark_id' => 'rb_txn_hold',
        'description' => 'AUTHORISATION',
        'clean_description' => null,
    ]);
    $child = $hold->createChild(['notes' => 'Weekly shop']);

    fakeNamedRows([namedRedbarkRow(['id' => 'rb_txn_settled', 'date' => '2026-08-14', 'postDate' => '2026-08-14'])]);
    syncForNames($feed);

    expect(Transaction::query()->count())->toBe(2)
        ->and($child->fresh()->clean_description)->toBe('Woolworths')
        ->and($child->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Feed)
        ->and($child->fresh()->notes)->toBe('Weekly shop');
});

test('a name a rule wrote after the import read the row is never replaced', function (string $path) {
    [$feed, , $account] = linkedRedbarkFeed();

    if ($path === 'existing') {
        fakeNamedRows([namedRedbarkRow()]);
        syncForNames($feed);
        $row = Transaction::query()->sole();
    } else {
        $row = Transaction::factory()->create([
            'user_id' => $feed->user_id,
            'account_id' => $account->id,
            'amount' => -4250,
            'direction' => TransactionDirection::Debit,
            'source' => TransactionSource::Csv,
            'status' => TransactionStatus::Posted,
            'post_date' => '2026-08-10',
            'description' => 'WOOLWORTHS 1234 BONDI',
            'clean_description' => null,
            'redbark_id' => null,
        ]);
    }

    $raced = false;

    Transaction::retrieved(function (Transaction $loaded) use (&$raced, $row): void {
        if ($raced || $loaded->id !== $row->id) {
            return;
        }

        $raced = true;
        DB::table('transactions')->where('id', $loaded->id)->update([
            'clean_description' => 'Groceries',
            'clean_description_source' => CleanDescriptionSource::Rule->value,
        ]);
    });

    fakeNamedRows([namedRedbarkRow(['merchantName' => 'Woolworths Metro'])]);
    syncForNames($feed->fresh());

    expect($raced)->toBeTrue()
        ->and($row->fresh()->clean_description)->toBe('Groceries')
        ->and($row->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Rule);
})->with(['an existing row' => ['existing'], 'an adopted row' => ['adopted']]);

test('a narration holding only card-network or foreign markers is left unnamed', function (string $description) {
    [$feed] = linkedRedbarkFeed();
    fakeNamedRows([namedRedbarkRow(['description' => $description, 'merchantName' => null])]);

    syncForNames($feed);

    $transaction = Transaction::query()->sole();

    expect($transaction->clean_description)->toBeNull()
        ->and($transaction->clean_description_source)->toBeNull();
})->with([
    'network and card mask' => ['VISA 724493 #8357'],
    'network, foreign marker and amount' => ['VISA FRGN AMT-5.000000'],
    'network alone' => ['MASTERCARD'],
]);

test('a revision created while the sync holds the parent lock gets the refreshed name', function () {
    [$feed, $account] = linkedRedbarkFeed();
    fakeNamedRows([namedRedbarkRow(['description' => 'AUTHORISATION', 'merchantName' => null])]);
    syncForNames($feed);

    $parent = Transaction::query()->sole();
    $child = null;

    Transaction::saving(function (Transaction $saving) use ($parent, &$child): void {
        if ($child !== null || $saving->id !== $parent->id || ! $saving->isDirty('clean_description')) {
            return;
        }

        $child = Transaction::factory()->create([
            'user_id' => $parent->user_id,
            'account_id' => $parent->account_id,
            'parent_transaction_id' => $parent->id,
            'source' => $parent->source,
            'status' => TransactionStatus::Posted,
            'description' => $parent->description,
            'clean_description' => null,
            'clean_description_source' => null,
            'redbark_id' => null,
        ]);
    });

    fakeNamedRows([namedRedbarkRow(['merchantName' => 'Woolworths'])]);
    syncForNames($feed->fresh());

    expect($child)->not->toBeNull()
        ->and($child->fresh()->clean_description)->toBe('Woolworths')
        ->and($child->fresh()->clean_description_source)->toBe(CleanDescriptionSource::Feed);
});
