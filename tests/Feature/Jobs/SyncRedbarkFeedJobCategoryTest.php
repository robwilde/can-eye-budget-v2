<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\RefreshTrigger;
use App\Jobs\RunTransactionAnalysisJob;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\Category;
use App\Models\RedbarkFeed;
use App\Models\Transaction;
use App\Services\RedbarkClientFactory;
use App\Services\RedbarkTransactionMatcher;
use App\Services\TransactionIngestor;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([RunTransactionAnalysisJob::class]);
    config(['budget.feed_category_matching' => true]);
});

function categorySync(RedbarkFeed $feed): void
{
    new SyncRedbarkFeedJob($feed, RefreshTrigger::Manual)->handle(
        app(RedbarkClientFactory::class),
        app(TransactionIngestor::class),
        app(RedbarkTransactionMatcher::class),
    );
}

/** @param  array<string, mixed>  $overrides */
function categoryFeedRow(string $bankCategory, array $overrides = []): array
{
    return [
        'id' => 'rb_txn_cat',
        'accountId' => 'rb_acc_1',
        'accountName' => 'Everyday Account',
        'status' => 'posted',
        'date' => '2026-08-10',
        'postDate' => '2026-08-10',
        'description' => 'THE BISTRO BONDI',
        'amount' => '-42.50',
        'direction' => 'debit',
        'category' => $bankCategory,
        'merchantName' => 'The Bistro',
        ...$overrides,
    ];
}

it('maps a bank category to a category through an alias', function () {
    [$feed] = linkedRedbarkFeed();
    $restaurants = Category::factory()->for($feed->user)->create(['name' => 'Restaurants', 'is_hidden' => false]);

    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: [categoryFeedRow('Restaurant')]);

    categorySync($feed);

    $transaction = Transaction::query()->where('redbark_id', 'rb_txn_cat')->sole();

    expect($transaction->category_id)->toBe($restaurants->id)
        ->and($transaction->category_source)->toBe(CategorySource::Feed);
});

it('keeps an existing category when the feed supplies another', function () {
    [$feed] = linkedRedbarkFeed();
    $groceries = Category::factory()->for($feed->user)->create(['name' => 'Groceries', 'is_hidden' => false]);
    Category::factory()->for($feed->user)->create(['name' => 'Restaurants', 'is_hidden' => false]);

    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: [categoryFeedRow('Restaurant')]);
    categorySync($feed);

    Transaction::query()->where('redbark_id', 'rb_txn_cat')->sole()
        ->update(['category_id' => $groceries->id, 'category_source' => CategorySource::Manual]);

    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: [categoryFeedRow('Restaurant')]);
    categorySync($feed->fresh());

    $transaction = Transaction::query()->where('redbark_id', 'rb_txn_cat')->sole();

    expect($transaction->category_id)->toBe($groceries->id)
        ->and($transaction->category_source)->toBe(CategorySource::Manual);
});

it('fills a category on a later sync when an existing row has none', function () {
    [$feed] = linkedRedbarkFeed();

    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: [categoryFeedRow('Restaurant')]);
    categorySync($feed);

    expect(Transaction::query()->where('redbark_id', 'rb_txn_cat')->sole()->category_id)->toBeNull();

    $restaurants = Category::factory()->for($feed->user)->create(['name' => 'Restaurants', 'is_hidden' => false]);

    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: [categoryFeedRow('Restaurant')]);
    categorySync($feed->fresh());

    expect(Transaction::query()->where('redbark_id', 'rb_txn_cat')->sole()->category_id)->toBe($restaurants->id);
});

it('skips feed category mapping when the account matcher is off', function () {
    [$feed] = linkedRedbarkFeed([], ['category_matcher_enabled' => false]);
    Category::factory()->for($feed->user)->create(['name' => 'Restaurants', 'is_hidden' => false]);

    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: [categoryFeedRow('Restaurant')]);

    categorySync($feed);

    expect(Transaction::query()->where('redbark_id', 'rb_txn_cat')->sole()->category_id)->toBeNull();
});

it('skips feed category mapping while the feature flag is off', function () {
    config(['budget.feed_category_matching' => false]);
    [$feed] = linkedRedbarkFeed();
    Category::factory()->for($feed->user)->create(['name' => 'Restaurants', 'is_hidden' => false]);

    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: [categoryFeedRow('Restaurant')]);

    categorySync($feed);

    expect(Transaction::query()->where('redbark_id', 'rb_txn_cat')->sole()->category_id)->toBeNull();
});

it('never creates a category from a feed value', function () {
    [$feed] = linkedRedbarkFeed();
    Category::factory()->for($feed->user)->create(['name' => 'Groceries', 'is_hidden' => false]);
    $before = Category::query()->count();

    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: [categoryFeedRow('Spaceships')]);

    categorySync($feed);

    expect(Transaction::query()->where('redbark_id', 'rb_txn_cat')->sole()->category_id)->toBeNull()
        ->and(Category::query()->count())->toBe($before);
});

it('only matches against the account owner categories', function () {
    [$feed] = linkedRedbarkFeed();
    $stranger = Category::factory()->create(['name' => 'Restaurants', 'is_hidden' => false]);

    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: [categoryFeedRow('Restaurants')]);

    categorySync($feed);

    expect($stranger->user_id)->not->toBe($feed->user_id)
        ->and(Transaction::query()->where('redbark_id', 'rb_txn_cat')->sole()->category_id)->toBeNull();
});
