<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\TransactionSplitLine;
use App\Enums\RefreshTrigger;
use App\Jobs\RunTransactionAnalysisJob;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\AuditEvent;
use App\Models\RedbarkFeed;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Services\RedbarkClientFactory;
use App\Services\RedbarkTransactionMatcher;
use App\Services\TransactionIngestor;
use App\Services\Transactions\TransactionSplitter;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->travelTo('2026-08-14 09:00:00');
    Queue::fake([RunTransactionAnalysisJob::class]);
});

function splitSyncRun(RedbarkFeed $feed, array $rowOverrides = []): void
{
    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: [[
        'id' => 'rb_txn_split',
        'accountId' => 'rb_acc_1',
        'status' => 'posted',
        'date' => '2026-08-10',
        'postDate' => '2026-08-10',
        'description' => 'BUNNINGS 1234 BONDI',
        'amount' => '-42.50',
        'direction' => 'debit',
        'merchantName' => 'Bunnings',
        ...$rowOverrides,
    ]]);

    new SyncRedbarkFeedJob($feed->fresh(), RefreshTrigger::Manual)->handle(
        app(RedbarkClientFactory::class),
        app(TransactionIngestor::class),
        app(RedbarkTransactionMatcher::class),
    );
}

function splitFeedParent(RedbarkFeed $feed): Transaction
{
    splitSyncRun($feed);

    $row = Transaction::query()->where('redbark_id', 'rb_txn_split')->firstOrFail();

    app(TransactionSplitter::class)->save($feed->user_id, $row->id, [new TransactionSplitLine(2000), new TransactionSplitLine(2250)]);

    return Transaction::findCurrentVersion($row->id, $feed->user_id);
}

function splitFeedCurrent(RedbarkFeed $feed): Transaction
{
    $row = Transaction::query()->where('redbark_id', 'rb_txn_split')->firstOrFail();

    return Transaction::findCurrentVersion($row->id, $feed->user_id);
}

function splitFeedLiveChildren(RedbarkFeed $feed): int
{
    return Transaction::query()->where('user_id', $feed->user_id)->whereNotNull('split_parent_id')->count();
}

it('undoes the split when a sync changes the amount of a split parent', function () {
    [$feed] = linkedRedbarkFeed();
    $parent = splitFeedParent($feed);

    splitSyncRun($feed, ['amount' => '-45.00']);

    $current = splitFeedCurrent($feed);

    expect($parent->excluded)->toBeTrue()
        ->and($current->id)->toBe($parent->id)
        ->and($current->amount)->toBe(-4500)
        ->and($current->excluded)->toBeFalse()
        ->and(splitFeedLiveChildren($feed))->toBe(0)
        ->and($current->notes)->toContain('Split removed')
        ->and((int) Transaction::query()->where('user_id', $feed->user_id)->current()->countable()->sum('amount'))->toBe(-4500)
        ->and(AuditEvent::query()->where('action', 'sync.split_removed')->where('subject_id', (string) $parent->id)->where('user_id', $feed->user_id)->count())->toBe(1);
});

it('undoes the split when a sync changes the date of a split parent', function () {
    [$feed] = linkedRedbarkFeed();
    splitFeedParent($feed);

    splitSyncRun($feed, ['date' => '2026-08-11', 'postDate' => '2026-08-11']);

    $current = splitFeedCurrent($feed);

    expect($current->post_date->toDateString())->toBe('2026-08-11')
        ->and($current->excluded)->toBeFalse()
        ->and(splitFeedLiveChildren($feed))->toBe(0)
        ->and((int) Transaction::query()->where('user_id', $feed->user_id)->current()->countable()->sum('amount'))->toBe(-4250);
});

it('undoes the split of an edited split parent when a sync changes its amount', function () {
    [$feed] = linkedRedbarkFeed();
    $parent = splitFeedParent($feed);
    $edited = $parent->createChild(['notes' => 'my note']);

    splitSyncRun($feed, ['amount' => '-45.00']);

    $current = splitFeedCurrent($feed);

    expect($current->id)->toBe($edited->id)
        ->and($current->amount)->toBe(-4500)
        ->and($current->excluded)->toBeFalse()
        ->and($current->notes)->toContain('my note')
        ->and($current->notes)->toContain('Split removed')
        ->and(splitFeedLiveChildren($feed))->toBe(0)
        ->and((int) Transaction::query()->where('user_id', $feed->user_id)->current()->countable()->sum('amount'))->toBe(-4500);
});

it('keeps the split when a sync repeats the same amount and date', function () {
    [$feed] = linkedRedbarkFeed();
    $parent = splitFeedParent($feed);

    splitSyncRun($feed);

    $current = splitFeedCurrent($feed);

    expect($current->id)->toBe($parent->id)
        ->and($current->excluded)->toBeTrue()
        ->and(splitFeedLiveChildren($feed))->toBe(2)
        ->and($current->notes)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'sync.split_removed')->count())->toBe(0)
        ->and((int) Transaction::query()->where('user_id', $feed->user_id)->current()->countable()->sum('amount'))->toBe(-4250);
});

it('still undoes the split when moving the receipts of its lines passes the attachment limit', function () {
    [$feed] = linkedRedbarkFeed();
    $parent = splitFeedParent($feed);
    $child = Transaction::query()->where('split_parent_id', $parent->id)->firstOrFail();
    TransactionAttachment::factory()->count(TransactionAttachment::MAX_PER_TRANSACTION)->create(['transaction_id' => $parent->id]);
    $line = TransactionAttachment::factory()->create(['transaction_id' => $child->id]);

    splitSyncRun($feed, ['amount' => '-45.00']);

    expect(splitFeedLiveChildren($feed))->toBe(0)
        ->and(splitFeedCurrent($feed)->amount)->toBe(-4500)
        ->and($line->fresh()->transaction_id)->toBe($parent->id);
});
