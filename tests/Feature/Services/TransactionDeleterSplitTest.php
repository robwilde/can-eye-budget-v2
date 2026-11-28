<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\TransactionSplitLine;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\TransactionDeleter;
use App\Services\Transactions\TransactionSplitter;
use App\Services\Transactions\TransactionWriter;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function deleterRow(object $test, array $overrides = []): Transaction
{
    return Transaction::factory()->manual()->create($overrides + [
        'user_id' => $test->user->id,
        'account_id' => $test->account->id,
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-10-10',
        'status' => TransactionStatus::Posted,
        'transfer_pair_id' => null,
    ]);
}

function deleterSplit(object $test, Transaction $row): Transaction
{
    $total = abs($row->amount);
    $first = intdiv($total, 2);

    app(TransactionSplitter::class)->save($test->user->id, $row->id, [new TransactionSplitLine($first), new TransactionSplitLine($total - $first)]);

    return Transaction::findCurrentVersion($row->id, $test->user->id);
}

function deleterDelete(object $test, Transaction $current): void
{
    app(TransactionWriter::class)->delete($test->user, $current->id);
}

function deleterCounted(object $test): int
{
    return Transaction::query()->where('user_id', $test->user->id)->current()->countable()->sum('amount');
}

it('removes the whole split when an edited split parent is deleted', function () {
    $row = deleterRow($this);
    $parent = deleterSplit($this, $row);
    $edited = $parent->createChild(['notes' => 'edited after the split']);

    deleterDelete($this, $edited);

    $current = Transaction::findCurrentVersion($row->id, $this->user->id);

    expect($current->id)->toBe($row->id)
        ->and($current->excluded)->toBeFalse()
        ->and(Transaction::query()->where('split_parent_id', '!=', null)->count())->toBe(0)
        ->and(Transaction::query()->where('user_id', $this->user->id)->current()->where('excluded', true)->count())->toBe(0)
        ->and(deleterCounted($this))->toBe(10000);
});

it('removes the whole split when a split of an edited transaction is deleted', function () {
    $row = deleterRow($this);
    $edited = $row->createChild(['notes' => 'edited before the split']);
    $parent = deleterSplit($this, $edited);

    deleterDelete($this, $parent);

    $current = Transaction::findCurrentVersion($row->id, $this->user->id);

    expect($current->id)->toBe($row->id)
        ->and($current->excluded)->toBeFalse()
        ->and(Transaction::query()->where('split_parent_id', '!=', null)->count())->toBe(0)
        ->and(Transaction::query()->where('user_id', $this->user->id)->current()->where('excluded', true)->count())->toBe(0)
        ->and(deleterCounted($this))->toBe(10000);
});

it('keeps an exclusion a person set on the restored version', function () {
    $row = deleterRow($this, ['excluded' => true, 'excluded_source' => App\Enums\ExclusionSource::Manual]);
    $edited = $row->createChild(['excluded' => false, 'excluded_source' => null]);
    $parent = deleterSplit($this, $edited);

    deleterDelete($this, $parent);

    expect(Transaction::findCurrentVersion($row->id, $this->user->id)->excluded)->toBeTrue();
});

it('clears the split of a protected bank feed original instead of deleting it', function () {
    $row = deleterRow($this, ['source' => TransactionSource::Redbark, 'redbark_id' => 'rb_837_001']);
    $parent = deleterSplit($this, $row);

    deleterDelete($this, $parent);

    $current = Transaction::findCurrentVersion($row->id, $this->user->id);

    expect($current->id)->toBe($row->id)
        ->and($current->trashed())->toBeFalse()
        ->and($current->excluded)->toBeFalse()
        ->and(Transaction::query()->where('split_parent_id', $row->id)->count())->toBe(0)
        ->and(deleterCounted($this))->toBe(10000);
});

it('keeps an exclusion a person set on a protected bank feed original when its split is cleared', function () {
    $row = deleterRow($this, ['source' => TransactionSource::Redbark, 'redbark_id' => 'rb_837_004']);
    $parent = deleterSplit($this, $row);
    $parent->forceFill(['excluded_source' => App\Enums\ExclusionSource::Manual])->save();

    deleterDelete($this, $parent);

    $current = Transaction::findCurrentVersion($row->id, $this->user->id);

    expect($current->id)->toBe($row->id)
        ->and($current->trashed())->toBeFalse()
        ->and($current->excluded)->toBeTrue()
        ->and($current->excluded_source)->toBe(App\Enums\ExclusionSource::Manual)
        ->and(Transaction::query()->where('split_parent_id', $row->id)->count())->toBe(0);
});

it('still refuses to delete a protected bank feed original that is not split', function () {
    $row = deleterRow($this, ['source' => TransactionSource::Redbark, 'redbark_id' => 'rb_837_002']);

    expect(fn () => deleterDelete($this, $row))->toThrow(App\Exceptions\TransactionWriteRefusedException::class)
        ->and(app(TransactionDeleter::class)->deletable(Transaction::query()->whereKey($row->id))->count())->toBe(0);
});

it('bulk deletes a split parent with its lines and a plain row, and clears a protected split', function () {
    $manual = deleterSplit($this, deleterRow($this));
    $plain = deleterRow($this, ['amount' => 500]);
    $protected = deleterSplit($this, deleterRow($this, ['amount' => 700, 'source' => TransactionSource::Redbark, 'redbark_id' => 'rb_837_003']));

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$manual->id => true, $plain->id => true, $protected->id => true])
        ->call('bulkDelete');

    expect(Transaction::query()->whereKey($manual->id)->exists())->toBeFalse()
        ->and(Transaction::query()->where('split_parent_id', $manual->id)->exists())->toBeFalse()
        ->and(Transaction::query()->whereKey($plain->id)->exists())->toBeFalse()
        ->and(Transaction::query()->whereKey($protected->id)->exists())->toBeTrue()
        ->and(Transaction::query()->whereKey($protected->id)->value('excluded'))->toBeFalse()
        ->and(Transaction::query()->where('split_parent_id', $protected->id)->exists())->toBeFalse();
});
