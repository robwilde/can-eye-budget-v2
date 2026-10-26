<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransferLinkSource;
use App\Exceptions\AccountNotTrackedException;
use App\Exceptions\RealOppositeRowExistsException;
use App\Exceptions\TransferLegsTooFarApartException;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\Transfers\TransferDetector;
use App\Services\Transfers\TransferLinker;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-20');
    $this->user = User::factory()->create();
});

function matchingAccount(User $user, int $opening = 100000, bool $tracked = true): Account
{
    $account = Account::factory()->for($user)->create(['balance' => 0, 'balance_source' => ImportSource::Manual, 'is_tracked' => $tracked]);
    AccountValuation::factory()->for($account)->opening()->create(['valued_on' => '2026-09-01', 'balance' => $opening]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($account);

    return $account;
}

function matchingRow(User $user, Account $account, int $cents, string $date, TransactionSource $source = TransactionSource::Redbark, string $description = 'Transfer'): Transaction
{
    return Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'direction' => $cents < 0 ? TransactionDirection::Debit : TransactionDirection::Credit,
        'amount' => $cents,
        'post_date' => $date,
        'description' => $description,
        'source' => $source,
        'redbark_id' => $source === TransactionSource::Redbark ? 'rb_'.fake()->unique()->numerify('######') : null,
    ]);
}

function matchingEnd(Account $account, string $date): int
{
    return $account->dailyBalances()->whereDate('balance_date', $date)->sole()->end_balance;
}

test('allows thirty days for confirmed transfers and the suggestion window for suggested ones', function () {
    $a = matchingAccount($this->user);
    $b = matchingAccount($this->user);
    $linker = app(TransferLinker::class);

    $out = matchingRow($this->user, $a, -5000, '2026-08-20');
    $in = matchingRow($this->user, $b, 5000, '2026-09-14');

    expect($linker->link($out, $in, TransferLinkSource::Confirmed))->toBeTrue()
        ->and($out->fresh()->transfer_pair_id)->toBe($in->id);

    $suggestOut = matchingRow($this->user, $a, -7000, '2026-09-01');
    $suggestIn = matchingRow($this->user, $b, 7000, '2026-09-06');

    expect($linker->suggest($suggestOut, $suggestIn))->toBeFalse()
        ->and($suggestOut->fresh()->suggested_pair_id)->toBeNull();

    $this->artisan('transfers:detect')->assertSuccessful();

    expect($suggestOut->fresh()->suggested_pair_id)->toBeNull();
});

test('refuses to link legs more than thirty days apart', function () {
    $a = matchingAccount($this->user);
    $b = matchingAccount($this->user);
    $out = matchingRow($this->user, $a, -5000, '2026-08-01');
    $in = matchingRow($this->user, $b, 5000, '2026-09-02');

    expect(fn () => app(TransferLinker::class)->link($out, $in, TransferLinkSource::Manual))
        ->toThrow(TransferLegsTooFarApartException::class)
        ->and($out->fresh()->transfer_pair_id)->toBeNull();
});

test('offers an opposite row up to thirty days away in the manual match list', function () {
    $a = matchingAccount($this->user);
    $b = matchingAccount($this->user);
    $out = matchingRow($this->user, $a, -5000, '2026-09-10');
    $near = matchingRow($this->user, $b, 5000, '2026-09-30');
    matchingRow($this->user, $b, 5000, '2026-10-20');

    $candidates = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $out->id)
        ->set('transactionType', 'transfer')
        ->instance()
        ->transferCandidates();

    expect($candidates->pluck('id')->all())->toBe([$near->id]);
});

test('keeps the surviving leg when one transfer leg is deleted', function () {
    $a = matchingAccount($this->user);
    $b = matchingAccount($this->user, 20000);
    $debit = matchingRow($this->user, $a, -5000, '2026-09-10', TransactionSource::Manual);
    $credit = matchingRow($this->user, $b, 5000, '2026-09-10', TransactionSource::Manual);
    $debit->forceFill(['transfer_pair_id' => $credit->id, 'transfer_link_source' => TransferLinkSource::Manual])->save();
    $credit->forceFill(['transfer_pair_id' => $debit->id, 'transfer_link_source' => TransferLinkSource::Manual])->save();
    app(AccountBalanceHistoryBuilder::class)->rebuild($a);
    app(AccountBalanceHistoryBuilder::class)->rebuild($b);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->call('deleteTransaction');

    $survivor = $credit->fresh();

    expect(Transaction::query()->whereKey($debit->id)->exists())->toBeFalse()
        ->and($survivor)->not->toBeNull()
        ->and($survivor->transfer_pair_id)->toBeNull()
        ->and($survivor->transfer_link_source)->toBe(TransferLinkSource::Unlinked)
        ->and(matchingEnd($a, '2026-09-20'))->toBe(100000)
        ->and(matchingEnd($b, '2026-09-20'))->toBe(25000);
});

test('never suggests a rejected pair again', function () {
    $a = matchingAccount($this->user);
    $b = matchingAccount($this->user);
    $out = matchingRow($this->user, $a, -5000, '2026-09-10');
    $in = matchingRow($this->user, $b, 5000, '2026-09-11');
    $detector = app(TransferDetector::class);

    expect($detector->run($this->user)['suggested'])->toBe(1);

    app(TransferLinker::class)->markNotTransfer($out->fresh());

    expect($detector->run($this->user)['suggested'])->toBe(0)
        ->and($in->fresh()->suggested_pair_id)->toBeNull();
});

test('suggests a pair of manual or csv rows that carry a transfer signal', function () {
    $a = matchingAccount($this->user);
    $b = matchingAccount($this->user);
    $out = matchingRow($this->user, $a, -5000, '2026-09-10', TransactionSource::Csv, 'Transfer to savings');
    $in = matchingRow($this->user, $b, 5000, '2026-09-11', TransactionSource::Manual, 'Transfer from everyday');

    $result = app(TransferDetector::class)->run($this->user);

    expect($result['suggested'])->toBe(1)
        ->and($out->fresh()->suggested_pair_id)->toBe($in->id);
});

test('does not suggest manual rows without a transfer signal', function () {
    $a = matchingAccount($this->user);
    $b = matchingAccount($this->user);
    matchingRow($this->user, $a, -5000, '2026-09-10', TransactionSource::Csv, 'Coffee');
    matchingRow($this->user, $b, 5000, '2026-09-11', TransactionSource::Manual, 'Refund');

    expect(app(TransferDetector::class)->run($this->user)['suggested'])->toBe(0);
});

test('creates a missing leg on a chosen account for a bank feed row', function () {
    $from = matchingAccount($this->user);
    $to = matchingAccount($this->user, 20000);
    $feed = matchingRow($this->user, $from, -5000, '2026-09-10');

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $feed->id)
        ->set('transactionType', 'transfer')
        ->set('transferToAccountId', $to->id)
        ->call('save')
        ->assertHasNoErrors();

    $leg = Transaction::query()->where('account_id', $to->id)->sole();

    expect($leg->direction)->toBe(TransactionDirection::Credit)
        ->and(abs($leg->amount))->toBe(5000)
        ->and($leg->source)->toBe(TransactionSource::Manual)
        ->and($leg->transfer_pair_id)->toBe($feed->id)
        ->and($feed->fresh()->transfer_pair_id)->toBe($leg->id)
        ->and($feed->fresh()->transfer_link_source)->toBe(TransferLinkSource::Manual)
        ->and(matchingEnd($to, '2026-09-20'))->toBe(25000);
});

test('links to a real row instead of creating a leg when one exists within thirty days', function () {
    $from = matchingAccount($this->user);
    $to = matchingAccount($this->user);
    $feed = matchingRow($this->user, $from, -5000, '2026-09-01');
    $real = matchingRow($this->user, $to, 5000, '2026-09-20');

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $feed->id)
        ->set('transactionType', 'transfer')
        ->set('transferToAccountId', $to->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::query()->count())->toBe(2)
        ->and($feed->fresh()->transfer_pair_id)->toBe($real->id);
});

test('refuses to create a missing leg on an untracked account through the tracked path', function () {
    $from = matchingAccount($this->user);
    $hidden = Account::factory()->for($this->user)->untracked()->create();
    $feed = matchingRow($this->user, $from, -5000, '2026-09-10');

    expect(fn () => app(TransferLinker::class)->createMissingLeg($feed, $hidden, TransferLinkSource::Manual))
        ->toThrow(AccountNotTrackedException::class)
        ->and(Transaction::query()->count())->toBe(1);
});

test('cannot create a missing leg on another users account', function () {
    $from = matchingAccount($this->user);
    $foreign = matchingAccount(User::factory()->create());
    $feed = matchingRow($this->user, $from, -5000, '2026-09-10');

    expect(fn () => app(TransferLinker::class)->createMissingLeg($feed, $foreign, TransferLinkSource::Manual))
        ->toThrow(InvalidArgumentException::class)
        ->and(Transaction::query()->count())->toBe(1);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $feed->id)
        ->set('transactionType', 'transfer')
        ->set('transferToAccountId', $foreign->id)
        ->call('save')
        ->assertHasErrors(['transferToAccountId']);

    expect(Transaction::query()->count())->toBe(1);
});

test('never offers another users transaction as a transfer candidate', function () {
    $mine = matchingAccount($this->user);
    $theirs = matchingAccount(User::factory()->create());
    $feed = matchingRow($this->user, $mine, -5000, '2026-09-10');
    matchingRow($theirs->user, $theirs, 5000, '2026-09-12');

    $candidates = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $feed->id)
        ->set('transactionType', 'transfer')
        ->instance()
        ->transferCandidates();

    expect($candidates)->toBeEmpty();
});

test('records an audit event for a manual transfer link', function () {
    $from = matchingAccount($this->user);
    $to = matchingAccount($this->user);
    $feed = matchingRow($this->user, $from, -5000, '2026-09-10');
    matchingRow($this->user, $to, 5000, '2026-09-18');

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $feed->id)
        ->set('transactionType', 'transfer')
        ->call('save');

    expect(AuditEvent::query()->where('action', 'livewire.transaction-modal.save')->where('user_id', $this->user->id)->exists())->toBeTrue();
});

test('counts the surviving leg of a deleted transfer as ordinary spend or income', function () {
    $transfer = Category::factory()->create(['name' => 'Transfer', 'parent_id' => null]);
    $a = matchingAccount($this->user);
    $b = matchingAccount($this->user);
    $debit = matchingRow($this->user, $a, -5000, '2026-09-10', TransactionSource::Manual);
    $credit = matchingRow($this->user, $b, 5000, '2026-09-10', TransactionSource::Manual);
    $debit->forceFill(['category_id' => $transfer->id])->save();
    $credit->forceFill(['category_id' => $transfer->id])->save();
    app(TransferLinker::class)->link($debit, $credit, TransferLinkSource::Manual);

    expect(Transaction::query()->countable()->whereKey($credit->id)->exists())->toBeFalse();

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->call('deleteTransaction');

    $survivor = $credit->fresh();

    expect($survivor->transfer_pair_id)->toBeNull()
        ->and($survivor->transfer_link_source)->toBe(TransferLinkSource::Unlinked)
        ->and(Transaction::query()->countable()->whereKey($credit->id)->exists())->toBeTrue();
});

test('a surviving leg can still be linked by hand', function () {
    $a = matchingAccount($this->user);
    $b = matchingAccount($this->user);
    $c = matchingAccount($this->user);
    $debit = matchingRow($this->user, $a, -5000, '2026-09-10', TransactionSource::Manual);
    $credit = matchingRow($this->user, $b, 5000, '2026-09-10', TransactionSource::Manual);
    app(TransferLinker::class)->link($debit, $credit, TransferLinkSource::Manual);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->call('deleteTransaction');

    $other = matchingRow($this->user, $c, -5000, '2026-09-12', TransactionSource::Redbark);

    expect(app(TransferLinker::class)->candidatesFor($other, includeRejected: true, windowDays: TransferLinker::MANUAL_WINDOW_DAYS)->pluck('id')->all())->toBe([$credit->id]);
});

test('deletes the mirror leg on an untracked account with its row', function () {
    $main = matchingAccount($this->user);
    $hidden = matchingAccount($this->user, 20000, tracked: false);
    $row = matchingRow($this->user, $main, -5000, '2026-09-10', TransactionSource::Manual);
    $mirror = app(TransferLinker::class)->linkToUntrackedAccount($row, $hidden, TransferLinkSource::Manual);

    expect(matchingEnd($hidden, '2026-09-20'))->toBe(25000);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $row->id)
        ->call('deleteTransaction');

    expect(Transaction::query()->whereKey($mirror->id)->exists())->toBeFalse()
        ->and(Transaction::withTrashed()->whereKey($mirror->id)->exists())->toBeTrue()
        ->and(matchingEnd($hidden, '2026-09-20'))->toBe(20000);
});

test('keeps a bank feed row on an untracked account when its partner leg is dissolved', function () {
    $main = matchingAccount($this->user);
    $hidden = matchingAccount($this->user, 20000, tracked: false);
    $row = matchingRow($this->user, $main, -5000, '2026-09-10', TransactionSource::Manual);
    $feed = matchingRow($this->user, $hidden, 5000, '2026-09-10');
    $row->forceFill(['transfer_pair_id' => $feed->id])->save();
    $feed->forceFill(['transfer_pair_id' => $row->id])->save();

    app(TransferLinker::class)->dissolve($row);

    $survivor = $feed->fresh();

    expect($survivor)->not->toBeNull()
        ->and($survivor->transfer_pair_id)->toBeNull()
        ->and($survivor->transfer_link_source)->toBe(TransferLinkSource::Unlinked);
});

test('deletes the leg the user opened, whichever side it is', function (string $opened) {
    $a = matchingAccount($this->user);
    $b = matchingAccount($this->user, 20000);
    $debit = matchingRow($this->user, $a, -5000, '2026-09-10', TransactionSource::Manual);
    $credit = matchingRow($this->user, $b, 5000, '2026-09-10', TransactionSource::Manual);
    app(TransferLinker::class)->link($debit, $credit, TransferLinkSource::Manual);
    app(AccountBalanceHistoryBuilder::class)->rebuild($a);
    app(AccountBalanceHistoryBuilder::class)->rebuild($b);
    $selected = $opened === 'credit' ? $credit : $debit;
    $survivor = $opened === 'credit' ? $debit : $credit;

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $selected->id)
        ->call('deleteTransaction');

    expect(Transaction::query()->whereKey($selected->id)->exists())->toBeFalse()
        ->and(Transaction::query()->whereKey($survivor->id)->exists())->toBeTrue()
        ->and(matchingEnd($a, '2026-09-20'))->toBe($opened === 'credit' ? 95000 : 100000)
        ->and(matchingEnd($b, '2026-09-20'))->toBe($opened === 'credit' ? 20000 : 25000);
})->with(['inflow' => 'credit', 'outflow' => 'debit']);

test('refuses to create a missing leg when a real match has appeared on that account', function () {
    $from = matchingAccount($this->user);
    $to = matchingAccount($this->user);
    $feed = matchingRow($this->user, $from, -5000, '2026-09-10');
    matchingRow($this->user, $to, 5000, '2026-09-11');

    expect(fn () => app(TransferLinker::class)->createMissingLeg($feed, $to, TransferLinkSource::Manual))
        ->toThrow(RealOppositeRowExistsException::class)
        ->and(Transaction::query()->count())->toBe(2)
        ->and($feed->fresh()->transfer_pair_id)->toBeNull();
});

test('a suggestion follows a notes edit of a manual row and can still be confirmed', function () {
    $a = matchingAccount($this->user);
    $b = matchingAccount($this->user);
    $out = matchingRow($this->user, $a, -5000, '2026-09-10', TransactionSource::Manual, 'Transfer to savings');
    $in = matchingRow($this->user, $b, 5000, '2026-09-11', TransactionSource::Csv, 'Transfer from everyday');
    app(TransferDetector::class)->run($this->user);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $out->id)
        ->set('notes', 'rent float')
        ->call('save');

    $child = Transaction::query()->where('parent_transaction_id', $out->id)->sole();

    expect($child->suggested_pair_id)->toBe($in->id)
        ->and($in->fresh()->suggested_pair_id)->toBe($child->id)
        ->and(app(TransferLinker::class)->confirm($child))->toBeTrue()
        ->and($child->fresh()->transfer_pair_id)->toBe($in->id);
});

test('drops a suggestion on both sides when an edit changes the amount', function () {
    $a = matchingAccount($this->user);
    $b = matchingAccount($this->user);
    $out = matchingRow($this->user, $a, -5000, '2026-09-10', TransactionSource::Manual, 'Transfer to savings');
    $in = matchingRow($this->user, $b, 5000, '2026-09-11', TransactionSource::Csv, 'Transfer from everyday');
    app(TransferDetector::class)->run($this->user);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $out->id)
        ->set('descriptionInput', '60 Transfer to savings')
        ->call('save');

    $child = Transaction::query()->where('parent_transaction_id', $out->id)->sole();

    expect($child->suggested_pair_id)->toBeNull()
        ->and($in->fresh()->suggested_pair_id)->toBeNull();
});

test('deleting an edited transfer leg leaves the restored earlier version unlinked', function () {
    $a = matchingAccount($this->user);
    $b = matchingAccount($this->user, 20000);
    $debit = matchingRow($this->user, $a, -5000, '2026-09-10', TransactionSource::Manual);
    $credit = matchingRow($this->user, $b, 5000, '2026-09-10', TransactionSource::Manual);
    $debit->forceFill(['transfer_pair_id' => $credit->id, 'transfer_link_source' => TransferLinkSource::Manual])->save();
    $credit->forceFill(['transfer_pair_id' => $debit->id, 'transfer_link_source' => TransferLinkSource::Manual])->save();

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debit->id)
        ->set('notes', 'rent float')
        ->call('save')
        ->assertHasNoErrors();

    $debitChild = Transaction::query()->where('parent_transaction_id', $debit->id)->sole();

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $debitChild->id)
        ->call('deleteTransaction');

    $restored = Transaction::findCurrentVersion($debit->id, $this->user->id);

    expect($restored?->id)->toBe($debit->id)
        ->and($restored->transfer_pair_id)->toBeNull()
        ->and($restored->transfer_link_source)->toBe(TransferLinkSource::Unlinked)
        ->and($credit->fresh()->transfer_pair_id)->toBeNull()
        ->and(Transaction::query()->current()->where('user_id', $this->user->id)->whereNotNull('transfer_pair_id')->count())->toBe(0);
});

test('converting a suggested manual row to a transfer releases the suggestion on its partner', function () {
    $a = matchingAccount($this->user);
    $b = matchingAccount($this->user);
    $c = matchingAccount($this->user);
    $out = matchingRow($this->user, $a, -5000, '2026-09-10', TransactionSource::Manual, 'Transfer to savings');
    $in = matchingRow($this->user, $b, 5000, '2026-09-11', TransactionSource::Csv, 'Transfer from everyday');
    app(TransferDetector::class)->run($this->user);

    expect($in->fresh()->suggested_pair_id)->toBe($out->id);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-transaction', id: $out->id)
        ->set('transactionType', 'transfer')
        ->set('transferToAccountId', $c->id)
        ->call('save')
        ->assertHasNoErrors();

    $debitChild = Transaction::query()->where('parent_transaction_id', $out->id)->sole();

    expect($debitChild->suggested_pair_id)->toBeNull()
        ->and($debitChild->transfer_pair_id)->not->toBeNull()
        ->and($in->fresh()->suggested_pair_id)->toBeNull()
        ->and(app(TransferLinker::class)->markNotTransfer($in->fresh()))->toBeTrue();
});
