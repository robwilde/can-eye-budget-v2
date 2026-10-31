<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\RefreshTrigger;
use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Jobs\SyncRedbarkFeedJob;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PendingMergeService;
use App\Services\RedbarkClientFactory;
use App\Services\RedbarkTransactionMatcher;
use App\Services\TransactionFeeFolder;
use App\Services\TransactionIngestor;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->travelTo('2026-08-14 09:00:00');
});

function lockSyncRow(array $overrides = []): array
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

function lockSync(object $feed, array $rows): void
{
    fakeRedbark(accounts: [redbarkUpstreamAccount()], transactions: $rows);

    new SyncRedbarkFeedJob($feed, RefreshTrigger::Manual)->handle(
        app(RedbarkClientFactory::class),
        app(TransactionIngestor::class),
        app(RedbarkTransactionMatcher::class),
    );
}

test('keeps user-edited fields through a bank sync until unlocked', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    lockSync($feed, [lockSyncRow()]);
    $row = Transaction::query()->where('account_id', $account->id)->sole();
    $row->forceFill(['locked_fields' => ['merchant_name']])->save();

    lockSync($feed->fresh(), [lockSyncRow(['merchantName' => 'Woolies Metro', 'description' => 'WOOLWORTHS 9999 BONDI'])]);

    $row->refresh();

    expect($row->merchant_name)->toBe('Woolworths')
        ->and($row->description)->toBe('WOOLWORTHS 9999 BONDI');

    Livewire::actingAs($feed->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->call('unlockTransaction');

    lockSync($feed->fresh(), [lockSyncRow(['merchantName' => 'Woolies Metro'])]);

    expect($row->fresh()->merchant_name)->toBe('Woolies Metro')
        ->and($row->fresh()->locked_fields)->toBeNull();
});

test('a user edit of a bank row locks the changed field and the sync still updates the rest of the current version', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    lockSync($feed, [lockSyncRow(['status' => 'pending', 'description' => 'WOOLWORTHS HOLD'])]);
    $original = Transaction::query()->where('account_id', $account->id)->sole();

    Livewire::actingAs($feed->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $original->id)
        ->set('notes', 'weekly shop')
        ->call('save');

    $edited = Transaction::query()->where('parent_transaction_id', $original->id)->sole();

    expect($edited->locked_fields)->toBe(['notes']);

    lockSync($feed->fresh(), [lockSyncRow(['status' => 'posted', 'description' => 'WOOLWORTHS 1234 BONDI'])]);

    $edited->refresh();

    expect($edited->notes)->toBe('weekly shop')
        ->and($edited->status)->toBe(TransactionStatus::Posted)
        ->and($edited->description)->toBe('WOOLWORTHS 1234 BONDI');
});

test('records which fields a manual edit locked and carries them to the next version', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->for($user)->create(['is_hidden' => false]);
    $row = Transaction::factory()->for($user)->for($account)->manual()->create(['amount' => 4250, 'direction' => TransactionDirection::Debit, 'description' => 'lunch', 'post_date' => '2026-08-10']);

    Livewire::actingAs($user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->set('categoryId', $category->id)
        ->call('save');

    $first = Transaction::query()->where('parent_transaction_id', $row->id)->sole();

    expect($first->locked_fields)->toBe(['category_id'])
        ->and($first->createChild(['notes' => 'x'])->locked_fields)->toBe(['category_id']);
});

test('an unchanged save locks nothing', function () {
    $user = User::factory()->create();
    $row = Transaction::factory()->for($user)->for(Account::factory()->for($user))->manual()->create(['amount' => 4250, 'direction' => TransactionDirection::Debit, 'description' => 'lunch', 'post_date' => '2026-08-10']);

    Livewire::actingAs($user)->test(TransactionModal::class)->dispatch('edit-transaction', id: $row->id)->call('save');

    expect(Transaction::query()->where('parent_transaction_id', $row->id)->sole()->locked_fields)->toBeNull();
});

test('cannot unlock another users transaction', function () {
    $other = User::factory()->create();
    $theirs = Transaction::factory()->for($other)->for(Account::factory()->for($other))->manual()->create(['locked_fields' => ['notes']]);
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(TransactionModal::class)->dispatch('edit-transaction', id: $theirs->id)->call('unlockTransaction');

    expect($theirs->fresh()->locked_fields)->toBe(['notes']);
});

test('records an audit event for an unlock', function () {
    $user = User::factory()->create();
    $row = Transaction::factory()->for($user)->for(Account::factory()->for($user))->manual()->create(['locked_fields' => ['notes']]);

    Livewire::actingAs($user)->test(TransactionModal::class)->dispatch('edit-transaction', id: $row->id)->call('unlockTransaction');

    expect(AuditEvent::query()->where('action', 'livewire.transaction-modal.unlockTransaction')->sole()->outcome)->toBe(AuditOutcome::Success);
});

test('merges a pending row from the modal and records an audit event', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $pending = Transaction::factory()->for($user)->for($account)->manual()->create(['amount' => 5000, 'direction' => TransactionDirection::Debit, 'description' => 'Corner Cafe Main', 'post_date' => '2026-08-10', 'status' => TransactionStatus::Pending]);
    $posted = Transaction::factory()->for($user)->for($account)->manual()->create(['amount' => 6000, 'direction' => TransactionDirection::Debit, 'description' => 'Corner Cafe Main St', 'post_date' => '2026-08-12', 'status' => TransactionStatus::Posted]);

    $component = Livewire::actingAs($user)->test(TransactionModal::class)->dispatch('edit-transaction', id: $pending->id);

    expect($component->viewData('mergeSuggestion')?->id)->toBe($posted->id);

    $component->call('mergePending', $posted->id)->assertSet('showModal', false);

    expect(Transaction::query()->current()->where('account_id', $account->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'livewire.transaction-modal.mergePending')->where('user_id', $user->id)->exists())->toBeTrue();
});

test('a refused merge shows an error and changes nothing', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $other = User::factory()->create();
    $pending = Transaction::factory()->for($user)->for($account)->manual()->create(['status' => TransactionStatus::Pending]);
    $theirs = Transaction::factory()->for($other)->for(Account::factory()->for($other))->manual()->create(['status' => TransactionStatus::Posted]);

    Livewire::actingAs($user)->test(TransactionModal::class)->dispatch('edit-transaction', id: $pending->id)
        ->call('mergePending', $theirs->id)
        ->assertHasErrors(['mergeCandidate']);

    expect(Transaction::query()->whereKey($pending->id)->exists())->toBeTrue();
});

test('shows ten merge candidates and ten more on request', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $pending = Transaction::factory()->for($user)->for($account)->manual()->create(['status' => TransactionStatus::Pending, 'post_date' => '2026-08-10']);
    Transaction::factory()->count(25)->for($user)->for($account)->manual()->create(['status' => TransactionStatus::Posted, 'post_date' => '2026-08-01']);

    $component = Livewire::actingAs($user)->test(TransactionModal::class)->dispatch('edit-transaction', id: $pending->id);

    expect($component->viewData('mergeCandidates'))->toHaveCount(10);

    $component->set('mergeCandidateLimit', 20);

    expect($component->viewData('mergeCandidates'))->toHaveCount(20);
});

test('a cleared display name stays cleared through a bank sync until unlocked', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    lockSync($feed, [lockSyncRow()]);
    $row = Transaction::query()->where('account_id', $account->id)->sole();

    $row->forceFill(['clean_description' => null, 'clean_description_source' => null, 'locked_fields' => ['clean_description']])->save();

    lockSync($feed->fresh(), [lockSyncRow()]);

    expect($row->fresh()->clean_description)->toBeNull();

    Livewire::actingAs($feed->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->call('unlockTransaction');

    lockSync($feed->fresh(), [lockSyncRow()]);

    expect($row->fresh()->clean_description)->toBe('Woolworths');
});

test('a folded fee keeps its combined amount through the next sync', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    lockSync($feed, [lockSyncRow(['description' => 'VISA -JetBrains Prague CZ FRGN AMT-9.57 951280 #8357', 'amount' => '-13.94'])]);
    $purchase = Transaction::query()->where('account_id', $account->id)->sole();
    $fee = Transaction::factory()->for($feed->user)->for($account)->create([
        'description' => 'Int Tran Fee - JetBrains CZ - 951280',
        'amount' => -42,
        'direction' => TransactionDirection::Debit,
        'post_date' => $purchase->post_date,
        'planned_transaction_id' => null,
        'transfer_pair_id' => null,
        'folded_into_transaction_id' => null,
    ]);

    $merged = app(TransactionFeeFolder::class)->fold($fee);
    $combined = $merged->amount;

    lockSync($feed->fresh(), [lockSyncRow(['description' => 'VISA -JetBrains Prague CZ FRGN AMT-9.57 951280 #8357', 'amount' => '-13.94'])]);

    expect($merged->fresh()->amount)->toBe($combined)
        ->and($combined)->not->toBe($purchase->amount);
});

test('a retained pending date survives the next sync of the posted row', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    $pendingRow = lockSyncRow(['id' => 'rb_txn_pending', 'status' => 'pending', 'date' => '2026-08-08', 'postDate' => '2026-08-08', 'description' => 'CORNER CAFE HOLD']);
    $postedRow = lockSyncRow(['id' => 'rb_txn_posted', 'date' => '2026-08-10', 'postDate' => '2026-08-10', 'description' => 'CORNER CAFE MAIN ST', 'amount' => '-60.00']);
    lockSync($feed, [$pendingRow, $postedRow]);

    $pending = Transaction::query()->where('redbark_id', 'rb_txn_pending')->sole();
    $posted = Transaction::query()->where('redbark_id', 'rb_txn_posted')->sole();
    $merged = app(PendingMergeService::class)->merge($feed->user, $pending->id, $posted->id);

    lockSync($feed->fresh(), [$pendingRow, $postedRow]);

    expect($merged->fresh()->post_date->toDateString())->toBe('2026-08-08')
        ->and(Transaction::query()->where('account_id', $account->id)->current()->count())->toBe(1);
});

test('unlocking a folded fee row keeps the combined amount through the next sync and hides the system lock', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    $row = lockSyncRow(['description' => 'VISA -JetBrains Prague CZ FRGN AMT-9.57 951280 #8357', 'amount' => '-13.94']);
    lockSync($feed, [$row]);
    $purchase = Transaction::query()->where('account_id', $account->id)->sole();
    $fee = Transaction::factory()->for($feed->user)->for($account)->create([
        'description' => 'Int Tran Fee - JetBrains CZ - 951280',
        'amount' => -42,
        'direction' => TransactionDirection::Debit,
        'post_date' => $purchase->post_date,
        'planned_transaction_id' => null,
        'transfer_pair_id' => null,
        'folded_into_transaction_id' => null,
    ]);
    $merged = app(TransactionFeeFolder::class)->fold($fee);
    $combined = $merged->amount;

    $component = Livewire::actingAs($feed->user)->test(TransactionModal::class)->dispatch('edit-transaction', id: $merged->id);

    expect($component->viewData('lockedFields'))->toBe([]);

    $component->call('unlockTransaction');
    lockSync($feed->fresh(), [$row]);

    expect($merged->fresh()->amount)->toBe($combined)
        ->and($merged->fresh()->locked_fields)->toBe(['amount']);
});

test('names the fields the user locked in words', function () {
    $user = User::factory()->create();
    $row = Transaction::factory()->for($user)->for(Account::factory()->for($user))->manual()->create(['locked_fields' => ['category_id', 'post_date']]);

    $component = Livewire::actingAs($user)->test(TransactionModal::class)->dispatch('edit-transaction', id: $row->id);

    expect($component->viewData('lockedFields'))->toBe(['category', 'date']);
});

test('cannot unlock or merge into another users transaction by tampering with the edited id', function () {
    $other = User::factory()->create();
    $theirs = Transaction::factory()->for($other)->for(Account::factory()->for($other))->manual()->create(['locked_fields' => ['notes'], 'status' => TransactionStatus::Pending]);
    $user = User::factory()->create();
    $mine = Transaction::factory()->for($user)->for(Account::factory()->for($user))->manual()->create(['status' => TransactionStatus::Pending]);

    Livewire::actingAs($user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $mine->id)
        ->set('editingTransactionId', $theirs->id)
        ->call('unlockTransaction')
        ->call('mergePending', $mine->id);

    expect($theirs->fresh()->locked_fields)->toBe(['notes'])
        ->and(Transaction::query()->whereKey($theirs->id)->exists())->toBeTrue()
        ->and(Transaction::query()->whereKey($mine->id)->exists())->toBeTrue();
});

test('the merge candidate page size resets when another transaction is opened', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $first = Transaction::factory()->for($user)->for($account)->manual()->create(['status' => TransactionStatus::Pending, 'post_date' => '2026-08-10']);
    $second = Transaction::factory()->for($user)->for($account)->manual()->create(['status' => TransactionStatus::Pending, 'post_date' => '2026-08-10']);
    Transaction::factory()->count(25)->for($user)->for($account)->manual()->create(['status' => TransactionStatus::Posted, 'post_date' => '2026-08-01']);

    $component = Livewire::actingAs($user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $first->id)
        ->set('mergeCandidateLimit', 20)
        ->dispatch('edit-transaction', id: $second->id);

    expect($component->viewData('mergeCandidates'))->toHaveCount(10);
});

test('a settled hold edited by the user also settles the original row the bank id sits on', function () {
    [$feed, , $account] = linkedRedbarkFeed();
    lockSync($feed, [lockSyncRow(['status' => 'pending', 'description' => 'WOOLWORTHS HOLD'])]);
    $original = Transaction::query()->where('account_id', $account->id)->sole();

    Livewire::actingAs($feed->user)->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $original->id)
        ->set('notes', 'weekly shop')
        ->call('save');

    lockSync($feed->fresh(), [lockSyncRow(['status' => 'posted'])]);

    expect($original->fresh()->status)->toBe(TransactionStatus::Posted);
});
