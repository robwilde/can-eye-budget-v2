<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RefreshTrigger;
use App\Enums\TransactionStatus;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\Transaction;
use App\Services\RedbarkClientFactory;
use App\Services\RedbarkTransactionMatcher;
use App\Services\TransactionIngestor;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo('2026-08-14 09:00:00');
});

function legacyRow(array $overrides = []): array
{
    return [
        'id' => 'rb_txn_1',
        'accountId' => 'rb_acc_1',
        'accountName' => 'Everyday Account',
        'status' => 'pending',
        'date' => '2026-08-10',
        'postDate' => '2026-08-10',
        'valueDate' => '2026-08-10',
        'description' => 'WOOLWORTHS HOLD',
        'amount' => '-42.50',
        'direction' => 'debit',
        'merchantName' => 'Woolworths',
        ...$overrides,
    ];
}

function legacySync(object $feed, array $rows): void
{
    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: $rows);

    new SyncRedbarkFeedJob($feed, RefreshTrigger::Manual)->handle(
        app(RedbarkClientFactory::class),
        app(TransactionIngestor::class),
        app(RedbarkTransactionMatcher::class),
    );
}

function backfillLockedFields(): void
{
    (require database_path('migrations/2026_10_26_300100_backfill_locked_fields_on_transactions.php'))->up();
}

test('a version edited before locks existed keeps its edit through a sync while other fields update', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    legacySync($feed, [legacyRow()]);
    $original = Transaction::query()->where('account_id', $account->id)->sole();
    $edited = $original->createChild(['notes' => 'Weekly shop']);

    expect($edited->locked_fields)->toBeNull();

    backfillLockedFields();

    expect($edited->fresh()->locked_fields)->toBe(['notes']);

    legacySync($feed->fresh(), [legacyRow(['status' => 'posted', 'description' => 'WOOLWORTHS 1234 BONDI'])]);

    $edited->refresh();

    expect($edited->notes)->toBe('Weekly shop')
        ->and($edited->description)->toBe('WOOLWORTHS 1234 BONDI')
        ->and($edited->status)->toBe(TransactionStatus::Posted);
});

test('a legacy version with no changed fields receives the full update and stays unlocked', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    legacySync($feed, [legacyRow()]);
    $original = Transaction::query()->where('account_id', $account->id)->sole();
    $untouched = $original->createChild([]);

    backfillLockedFields();

    expect($untouched->fresh()->locked_fields)->toBeNull();

    legacySync($feed->fresh(), [legacyRow(['status' => 'posted', 'description' => 'WOOLWORTHS 1234 BONDI'])]);

    $untouched->refresh();

    expect($untouched->description)->toBe('WOOLWORTHS 1234 BONDI')
        ->and($untouched->status)->toBe(TransactionStatus::Posted);
});

test('the backfill only locks the current version of a chain and leaves superseded versions and manual rows alone', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    legacySync($feed, [legacyRow()]);
    $original = Transaction::query()->where('account_id', $account->id)->sole();
    $first = $original->createChild(['clean_description' => 'Weekly shop', 'clean_description_source' => 'manual']);
    $second = $first->createChild(['notes' => 'with Sam']);
    $manual = Transaction::factory()->for($feed->user)->manual()->create();
    $manualEdit = $manual->createChild(['notes' => 'Edited by hand']);

    backfillLockedFields();

    expect($first->fresh()->locked_fields)->toBeNull()
        ->and($second->fresh()->locked_fields)->toBe(['clean_description', 'notes'])
        ->and($original->fresh()->locked_fields)->toBeNull()
        ->and($manualEdit->fresh()->locked_fields)->toBeNull();
});

test('fields the bank rewrote on the original row after the edit are not locked and still update', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    legacySync($feed, [legacyRow()]);
    $original = Transaction::query()->where('account_id', $account->id)->sole();
    $edited = $original->createChild(['notes' => 'with Sam']);

    $original->forceFill([
        'description' => 'WOOLWORTHS 1234 BONDI',
        'status' => TransactionStatus::Posted,
        'amount' => -4300,
        'post_date' => '2026-08-12',
    ])->saveQuietly();

    backfillLockedFields();

    expect($edited->fresh()->locked_fields)->toBe(['notes']);

    legacySync($feed->fresh(), [legacyRow(['status' => 'posted', 'description' => 'WOOLWORTHS 1234 BONDI', 'amount' => '-43.00', 'postDate' => '2026-08-12'])]);

    $edited->refresh();

    expect($edited->notes)->toBe('with Sam')
        ->and($edited->description)->toBe('WOOLWORTHS 1234 BONDI')
        ->and($edited->status)->toBe(TransactionStatus::Posted)
        ->and(abs($edited->amount))->toBe(4300);
});

test('a fee folded into the chain before the backfill keeps the combined amount through a sync', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    legacySync($feed, [legacyRow(['status' => 'posted'])]);
    $original = Transaction::query()->where('account_id', $account->id)->sole();
    $merged = $original->createChild(['amount' => $original->amount - 100]);
    $fee = Transaction::factory()->for($feed->user)->for($account)->manual()->create(['status' => TransactionStatus::Posted]);
    $fee->forceFill(['folded_into_transaction_id' => $merged->id])->save();
    $fee->delete();

    backfillLockedFields();

    expect($merged->fresh()->locked_fields)->toBe(['amount']);

    $combined = $merged->fresh()->amount;

    legacySync($feed->fresh(), [legacyRow(['status' => 'posted'])]);

    expect($merged->fresh()->amount)->toBe($combined);
});

test('a display name the bank sync derived on the original row is not locked, while a manual one is', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    legacySync($feed, [legacyRow()]);
    $original = Transaction::query()->where('account_id', $account->id)->sole();
    $derived = $original->createChild(['notes' => 'with Sam']);
    $original->forceFill(['clean_description' => 'Woolworths Bondi', 'clean_description_source' => 'derived'])->saveQuietly();

    backfillLockedFields();

    expect($derived->fresh()->locked_fields)->toBe(['notes']);

    Transaction::query()->whereKey($derived->id)->update(['locked_fields' => null, 'clean_description' => 'My shop', 'clean_description_source' => 'manual']);

    backfillLockedFields();

    expect($derived->fresh()->locked_fields)->toBe(['clean_description', 'notes']);
});
