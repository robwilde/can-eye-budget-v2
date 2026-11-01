<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\ImportSource;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Livewire\TransactionList;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Models\User;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\Transactions\TransactionSplitter;
use App\Services\Transfers\TransferLinker;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function splitRow(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->for($user)->for($account)->manual()->create(array_merge([
        'post_date' => '2026-10-10',
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'description' => 'HARDWARE '.fake()->unique()->numerify('####'),
        'transfer_pair_id' => null,
    ], $overrides));
}

/** @return array{name: string, amount: string, category_id: int|null, notes: string, excluded: bool} */
function splitLine(string $amount, string $name = '', ?int $categoryId = null, string $notes = '', bool $excluded = false): array
{
    return ['name' => $name, 'amount' => $amount, 'category_id' => $categoryId, 'notes' => $notes, 'excluded' => $excluded];
}

function splitModal(User $user, Transaction $row): Testable
{
    return Livewire::actingAs($user)->test(TransactionModal::class)->dispatch('edit-transaction', id: $row->id);
}

function splitCurrent(Transaction $row): Transaction
{
    $current = Transaction::findCurrentVersion($row->id, $row->user_id);

    expect($current)->toBeInstanceOf(Transaction::class);

    return $current;
}

function splitChildrenOf(Transaction $parent): Illuminate\Database\Eloquent\Collection
{
    return Transaction::query()->where('split_parent_id', $parent->id)->current()->orderBy('id')->get();
}

function splitReceiptOn(Transaction $transaction): TransactionAttachment
{
    $path = 'transaction-attachments/'.$transaction->id.'/'.fake()->uuid().'.pdf';
    Storage::disk('local')->put($path, 'file');

    return TransactionAttachment::factory()->create(['transaction_id' => $transaction->id, 'disk' => 'local', 'path' => $path]);
}

function splitInto(User $user, Transaction $row, array $lines): Transaction
{
    splitModal($user, $row)->set('splitLines', $lines)->call('saveSplit')->assertHasNoErrors();

    return splitCurrent($row);
}

it('rejects a split that does not sum to the parent amount', function () {
    $row = splitRow($this->user, $this->account);

    splitModal($this->user, $row)
        ->call('startSplit')
        ->set('splitLines', [splitLine('60'), splitLine('30')])
        ->call('saveSplit')
        ->assertHasErrors('splitLines')
        ->assertNotDispatched('transaction-saved');

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and($row->fresh()->excluded)->toBeFalse()
        ->and(Transaction::query()->whereNotNull('split_parent_id')->exists())->toBeFalse();
});

it('rejects a split with fewer than two lines or a line that is not above zero', function () {
    $row = splitRow($this->user, $this->account);

    splitModal($this->user, $row)->set('splitLines', [splitLine('100')])->call('saveSplit')->assertHasErrors('splitLines');
    splitModal($this->user, $row)->set('splitLines', [splitLine('100'), splitLine('0')])->call('saveSplit')->assertHasErrors('splitLines');
    splitModal($this->user, $row)->set('splitLines', [splitLine('110'), splitLine('-10')])->call('saveSplit')->assertHasErrors('splitLines');

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and($row->fresh()->excluded)->toBeFalse();
});

it('splits a transaction into two child transactions', function () {
    $food = Category::factory()->for($this->user)->create();
    $row = splitRow($this->user, $this->account, ['merchant_name' => 'Bunnings', 'notes' => 'parent notes']);

    splitModal($this->user, $row)
        ->call('startSplit')
        ->set('splitLines', [
            splitLine('60', 'Paint', $food->id, 'for the fence'),
            splitLine('40', 'Brushes', null, '', true),
        ])
        ->call('saveSplit')
        ->assertHasNoErrors()
        ->assertDispatched('transaction-saved');

    $parent = splitCurrent($row);
    $children = splitChildrenOf($parent);

    expect($parent->excluded)->toBeTrue()
        ->and($children)->toHaveCount(2)
        ->and($children->pluck('amount')->all())->toBe([6000, 4000])
        ->and($children->pluck('description')->all())->toBe(['Paint', 'Brushes'])
        ->and($children->pluck('category_id')->all())->toBe([$food->id, null])
        ->and($children->pluck('notes')->all())->toBe(['for the fence', null])
        ->and($children->pluck('excluded')->all())->toBe([false, true])
        ->and($children->pluck('direction')->unique()->all())->toBe([TransactionDirection::Debit])
        ->and($children->pluck('account_id')->unique()->all())->toBe([$this->account->id])
        ->and($children->pluck('merchant_name')->unique()->all())->toBe(['Bunnings'])
        ->and($children->pluck('merchant_key')->unique()->all())->toBe([$parent->merchant_key])
        ->and($children->map(fn (Transaction $child): string => $child->post_date->toDateString())->unique()->all())->toBe(['2026-10-10']);
});

it('counts the money of a split once in spend', function () {
    $row = splitRow($this->user, $this->account);

    splitInto($this->user, $row, [splitLine('60'), splitLine('40', excluded: true)]);

    $countable = Transaction::query()->where('user_id', $this->user->id)->current()->countable();

    expect((int) $countable->sum('amount'))->toBe(6000);
});

it('keeps a split parent excluded', function () {
    $row = splitRow($this->user, $this->account);
    $parent = splitInto($this->user, $row, [splitLine('60'), splitLine('40')]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('toggleExcluded', $parent->id, false)
        ->assertNotDispatched('transaction-saved');

    splitModal($this->user, $parent)
        ->assertSet('excluded', true)
        ->set('excluded', false)
        ->call('save')
        ->assertHasErrors('descriptionInput');

    expect(splitCurrent($row)->excluded)->toBeTrue()
        ->and(Transaction::query()->where('parent_transaction_id', $parent->id)->exists())->toBeFalse();
});

it('refuses to split ineligible transactions', function () {
    $otherAccount = Account::factory()->for($this->user)->create();
    $legA = splitRow($this->user, $this->account);
    $legB = splitRow($this->user, $otherAccount, ['direction' => TransactionDirection::Credit, 'transfer_pair_id' => $legA->id]);
    $legA->update(['transfer_pair_id' => $legB->id]);

    $parent = splitRow($this->user, $this->account);
    $splitParent = splitInto($this->user, $parent, [splitLine('60'), splitLine('40')]);
    $child = splitChildrenOf($splitParent)->first();

    $categoryLined = splitRow($this->user, $this->account);
    $categoryLined->splits()->create(['category_id' => Category::factory()->for($this->user)->create()->id, 'amount' => 10000]);

    $ineligible = [
        'transfer leg' => $legA,
        'split child' => $child,
        'pending row' => splitRow($this->user, $this->account, ['status' => TransactionStatus::Pending]),
        'excluded row' => splitRow($this->user, $this->account, ['excluded' => true]),
        'row with category lines' => $categoryLined,
    ];

    $before = Transaction::query()->count();

    foreach ($ineligible as $row) {
        $amount = number_format(abs($row->amount) / 100 / 2, 2, '.', '');

        splitModal($this->user, $row)
            ->call('startSplit')
            ->assertHasErrors('splitLines');

        splitModal($this->user, $row)
            ->set('splitLines', [splitLine($amount), splitLine($amount)])
            ->call('saveSplit')
            ->assertHasErrors('splitLines');
    }

    expect(Transaction::query()->count())->toBe($before)
        ->and(splitChildrenOf($splitParent))->toHaveCount(2);
});

it('undoes a split', function () {
    $row = splitRow($this->user, $this->account);
    $parent = splitInto($this->user, $row, [splitLine('60'), splitLine('40')]);

    splitModal($this->user, $parent)
        ->call('undoSplit')
        ->assertHasNoErrors()
        ->assertDispatched('transaction-saved');

    $restored = splitCurrent($row);

    expect($restored->excluded)->toBeFalse()
        ->and(splitChildrenOf($restored))->toBeEmpty()
        ->and(Transaction::query()->whereNotNull('split_parent_id')->count())->toBe(0)
        ->and(Transaction::query()->where('user_id', $this->user->id)->current()->countable()->pluck('id')->all())->toBe([$restored->id])
        ->and(Transaction::query()->withTrashed()->whereNotNull('split_parent_id')->count())->toBe(2);
});

it('edits a split by replacing its lines', function () {
    $row = splitRow($this->user, $this->account);
    $parent = splitInto($this->user, $row, [splitLine('60'), splitLine('40')]);

    $modal = splitModal($this->user, $parent)->call('startSplit');

    expect($modal->get('splitLines'))->toHaveCount(2)
        ->and($modal->get('splitLines.0.amount'))->toBe('60.00');

    $modal->set('splitLines', [splitLine('50'), splitLine('30'), splitLine('20')])->call('saveSplit')->assertHasNoErrors();

    expect(splitChildrenOf($parent)->pluck('amount')->all())->toBe([5000, 3000, 2000])
        ->and(splitCurrent($row)->excluded)->toBeTrue();

    splitModal($this->user, $parent)->set('splitLines', [splitLine('50'), splitLine('30')])->call('saveSplit')->assertHasErrors('splitLines');

    expect(splitChildrenOf($parent)->pluck('amount')->all())->toBe([5000, 3000, 2000]);
});

it('only deletes split children with their parent', function () {
    $row = splitRow($this->user, $this->account);
    $parent = splitInto($this->user, $row, [splitLine('60'), splitLine('40')]);
    [$first, $second] = splitChildrenOf($parent)->all();

    splitModal($this->user, $first)->call('deleteTransaction')->assertHasErrors('splitLines');

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$first->id => true])
        ->call('bulkDelete');

    expect(Transaction::query()->whereKey([$first->id, $second->id])->count())->toBe(2);

    splitModal($this->user, $parent)->call('deleteTransaction')->assertDispatched('transaction-saved');

    expect(Transaction::query()->whereKey([$first->id, $second->id, $parent->id])->count())->toBe(0)
        ->and(Transaction::query()->onlyTrashed()->whereKey([$first->id, $second->id, $parent->id])->count())->toBe(3);
});

it('does not split another users transaction', function () {
    $other = User::factory()->create();
    $foreign = splitRow($other, Account::factory()->for($other)->create());

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->set('editingTransactionId', $foreign->id)
        ->set('splitLines', [splitLine('60'), splitLine('40')])
        ->call('saveSplit')
        ->assertHasErrors('splitLines');

    $event = AuditEvent::query()->where('action', 'livewire.transaction-modal.saveSplit')->sole();

    expect($event->outcome)->toBe(AuditOutcome::Failure)
        ->and($foreign->fresh()->excluded)->toBeFalse()
        ->and(Transaction::query()->whereNotNull('split_parent_id')->exists())->toBeFalse()
        ->and(Transaction::query()->where('user_id', $other->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'like', 'livewire.transaction-modal.%')->pluck('outcome')->all())->toBe([AuditOutcome::Failure]);
});

it('does not undo another users split', function () {
    $other = User::factory()->create();
    $foreign = splitRow($other, Account::factory()->for($other)->create());
    $foreignParent = splitInto($other, $foreign, [splitLine('60'), splitLine('40')]);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->set('editingTransactionId', $foreignParent->id)
        ->call('undoSplit')
        ->assertHasErrors('splitLines');

    expect(splitCurrent($foreign)->excluded)->toBeTrue()
        ->and(splitChildrenOf($foreignParent))->toHaveCount(2);
});

it('moves the account balance once for a split', function () {
    $account = Account::factory()->for($this->user)->create(['balance' => 0, 'balance_source' => ImportSource::Manual]);
    AccountValuation::factory()->for($account)->opening()->create(['valued_on' => '2026-10-01', 'balance' => 100000]);
    $row = splitRow($this->user, $account);
    app(AccountBalanceHistoryBuilder::class)->rebuild($account);

    $endOf = fn (string $date): int => $account->dailyBalances()->whereDate('balance_date', $date)->sole()->end_balance;

    expect($endOf('2026-10-15'))->toBe(90000);

    $parent = splitInto($this->user, $row, [splitLine('60'), splitLine('40')]);

    expect($endOf('2026-10-15'))->toBe(90000)
        ->and((int) $account->dailyBalances()->whereDate('balance_date', '2026-10-10')->sole()->outflows)->toBe(10000);

    expectBalanceHistoryConsistent($account);

    splitModal($this->user, $parent)->call('undoSplit')->assertHasNoErrors();

    expect($endOf('2026-10-15'))->toBe(90000);

    expectBalanceHistoryConsistent($account);
});

it('keeps the children attached when a split parent is edited', function () {
    $row = splitRow($this->user, $this->account);
    $parent = splitInto($this->user, $row, [splitLine('60'), splitLine('40')]);

    splitModal($this->user, $parent)
        ->set('notes', 'edited after the split')
        ->call('save')
        ->assertHasNoErrors();

    $edited = splitCurrent($row);

    expect($edited->id)->not->toBe($parent->id)
        ->and($edited->notes)->toBe('edited after the split')
        ->and($edited->excluded)->toBeTrue()
        ->and(splitChildrenOf($edited)->pluck('amount')->all())->toBe([6000, 4000])
        ->and(splitChildrenOf($parent))->toBeEmpty()
        ->and(Transaction::query()->where('user_id', $this->user->id)->current()->countable()->sum('amount'))->toBe(10000);
});

it('refuses to change the amount, date, account or type of a split parent or line', function () {
    $other = Account::factory()->for($this->user)->create();
    $row = splitRow($this->user, $this->account);
    $parent = splitInto($this->user, $row, [splitLine('60'), splitLine('40')]);
    $child = splitChildrenOf($parent)->first();

    foreach ([$parent, $child] as $target) {
        splitModal($this->user, $target)->set('descriptionInput', '75 HARDWARE')->call('save')->assertHasErrors('descriptionInput');
        splitModal($this->user, $target)->set('date', '2026-10-11')->call('save')->assertHasErrors('descriptionInput');
        splitModal($this->user, $target)->set('accountId', $other->id)->call('save')->assertHasErrors('descriptionInput');
        splitModal($this->user, $target)->set('transactionType', 'income')->call('save')->assertHasErrors('descriptionInput');
    }

    expect(splitCurrent($row)->id)->toBe($parent->id)
        ->and(splitChildrenOf($parent)->pluck('amount')->all())->toBe([6000, 4000]);
});

it('keeps the line of a split on its parent when the line is edited', function () {
    $row = splitRow($this->user, $this->account);
    $parent = splitInto($this->user, $row, [splitLine('60', 'Paint'), splitLine('40', 'Brushes')]);
    $child = splitChildrenOf($parent)->first();

    splitModal($this->user, $child)->set('notes', 'line note')->call('save')->assertHasNoErrors();

    $lines = splitChildrenOf($parent);

    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('amount')->all())->toBe([4000, 6000])
        ->and($lines->firstWhere('notes', 'line note')->split_parent_id)->toBe($parent->id)
        ->and((int) Transaction::query()->where('user_id', $this->user->id)->current()->countable()->sum('amount'))->toBe(10000);
});

it('skips split parents in bulk edit and split children in bulk delete', function () {
    $row = splitRow($this->user, $this->account);
    $parent = splitInto($this->user, $row, [splitLine('60'), splitLine('40')]);
    [$first] = splitChildrenOf($parent)->all();
    $plain = splitRow($this->user, $this->account);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$parent->id => true, $plain->id => true])
        ->set('bulkEditInclude', ['notes'])
        ->set('bulkEditNotes', 'bulk')
        ->call('bulkEdit');

    expect(splitCurrent($row)->id)->toBe($parent->id)
        ->and(splitCurrent($row)->notes)->toBeNull()
        ->and(splitCurrent($plain)->notes)->toBe('bulk');
    $plainCurrentId = splitCurrent($plain)->id;

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$first->id => true, $plainCurrentId => true])
        ->call('bulkDelete');

    expect(Transaction::query()->whereKey($first->id)->exists())->toBeTrue()
        ->and(Transaction::query()->whereKey($plainCurrentId)->exists())->toBeFalse();
});

it('does not give a split row category lines from the list', function () {
    $row = splitRow($this->user, $this->account);
    $category = Category::factory()->for($this->user)->create();

    $list = Livewire::actingAs($this->user)->test(TransactionList::class)->call('toggleSplit', $row->id);

    $parent = splitInto($this->user, $row, [splitLine('60'), splitLine('40')]);

    $list->set('splitLines', [
        ['category_id' => (string) $category->id, 'amount' => '60', 'notes' => ''],
        ['category_id' => (string) $category->id, 'amount' => '40', 'notes' => ''],
    ])
        ->call('saveSplit')
        ->assertSet('splitError', 'A transaction split into transactions cannot also have category lines.');

    Livewire::actingAs($this->user)->test(TransactionList::class)->call('toggleSplit', $parent->id)->assertSet('splitPanelTxnId', null);

    expect($parent->splits()->exists())->toBeFalse();
});

it('does not fold a fee into a split purchase', function () {
    $purchase = splitRow($this->user, $this->account, [
        'description' => 'VISA -JetBrains Prague CZ FRGN AMT-9.570000 051280 #8357',
        'amount' => 1394,
    ]);
    splitInto($this->user, $purchase, [splitLine('10'), splitLine('3.94')]);
    $fee = splitRow($this->user, $this->account, ['description' => 'Int Tran Fee - JetBrains CZ - 951280', 'amount' => 42]);

    expect(app(App\Services\TransactionFeeFolder::class)->fold($fee))->toBeNull()
        ->and(splitCurrent($purchase)->amount)->toBe(1394);
});

it('does not merge a pending row into a split posted row', function () {
    $posted = splitRow($this->user, $this->account, ['amount' => 6000, 'post_date' => '2026-10-12']);
    splitInto($this->user, $posted, [splitLine('40'), splitLine('20')]);
    $pending = splitRow($this->user, $this->account, ['amount' => 5000, 'post_date' => '2026-10-10', 'status' => TransactionStatus::Pending]);

    expect(fn () => app(App\Services\PendingMergeService::class)->merge($this->user, $pending->id, $posted->id))
        ->toThrow(App\Exceptions\PendingMergeRefusedException::class);

    expect(splitCurrent($posted)->amount)->toBe(6000)
        ->and(splitChildrenOf(splitCurrent($posted)))->toHaveCount(2);
});

it('does not match a split line against a statement line', function () {
    $row = splitRow($this->user, $this->account);
    $parent = splitInto($this->user, $row, [splitLine('60'), splitLine('40')]);
    $matcher = app(App\Services\ReconciliationMatcher::class);
    $day = Carbon\CarbonImmutable::parse('2026-10-10');

    $plan = fn (int $cents): App\Models\PlannedTransaction => App\Models\PlannedTransaction::factory()->for($this->user)->for($this->account)->noRepeat()->create([
        'amount' => $cents,
        'direction' => TransactionDirection::Debit,
        'start_date' => '2026-10-10',
    ]);

    expect($matcher->findSuggestions($plan(10000), $day)->modelKeys())->toBe([$parent->id])
        ->and($matcher->findSuggestions($plan(6000), $day))->toBeEmpty()
        ->and($matcher->findSuggestions($plan(4000), $day))->toBeEmpty();

    app(App\Services\PlannedTransactionMatcher::class)->matchForUser($this->user);

    expect(Transaction::query()->whereNotNull('split_parent_id')->whereNotNull('planned_transaction_id')->exists())->toBeFalse();
});

it('refuses to change the currency or exchange rate of a split parent or line', function () {
    $row = splitRow($this->user, $this->account);
    $parent = splitInto($this->user, $row, [splitLine('60'), splitLine('40')]);
    $child = splitChildrenOf($parent)->first();

    foreach ([$parent, $child] as $target) {
        splitModal($this->user, $target)->set('currency', 'USD')->call('save')->assertHasErrors('descriptionInput');
        splitModal($this->user, $target)->set('exchangeRate', '1.5')->call('save')->assertHasErrors('descriptionInput');
    }

    expect(splitCurrent($row)->id)->toBe($parent->id)
        ->and(splitChildrenOf($parent)->pluck('currency')->unique()->all())->toBe([$parent->currency]);
});

it('keeps a split line in its parents merchant cluster when the line is edited', function () {
    $row = splitRow($this->user, $this->account, ['merchant_name' => null, 'description' => 'BUNNINGS 1234']);
    $parent = splitInto($this->user, $row, [splitLine('60', 'Paint'), splitLine('40', 'Brushes')]);
    $child = splitChildrenOf($parent)->first();

    splitModal($this->user, $child)->set('notes', 'edited')->call('save')->assertHasNoErrors();

    expect(splitChildrenOf($parent)->pluck('merchant_key')->unique()->all())->toBe([$parent->merchant_key]);
});

it('never adopts or links a split row as a bank posting or transfer leg', function () {
    $row = splitRow($this->user, $this->account, ['amount' => 6000]);
    $parent = splitInto($this->user, $row, [splitLine('36', 'Paint'), splitLine('24', 'Brushes')]);
    $child = splitChildrenOf($parent)->first();
    $plain = splitRow($this->user, $this->account, ['amount' => 3600, 'description' => 'Paint']);

    $adopted = app(App\Services\RedbarkTransactionMatcher::class)->findExisting($this->account->id, 3600, Carbon\CarbonImmutable::parse('2026-10-10'), 'Paint');
    $linkable = Transaction::query()->linkable()->pluck('id')->all();

    expect($adopted?->id)->toBe($plain->id)
        ->and($linkable)->toContain($plain->id)
        ->not->toContain($parent->id)
        ->not->toContain($child->id);
});

it('refuses to link a split row as a transfer leg', function () {
    $otherAccount = Account::factory()->for($this->user)->create();
    $row = splitRow($this->user, $this->account);
    $parent = splitInto($this->user, $row, [splitLine('60'), splitLine('40')]);
    $child = splitChildrenOf($parent)->first();
    $other = splitRow($this->user, $otherAccount, ['direction' => TransactionDirection::Credit]);
    $linker = app(TransferLinker::class);
    $source = App\Enums\TransferLinkSource::Manual;

    expect($linker->link($parent, $other, $source))->toBeFalse()
        ->and($linker->link($child, $other, $source))->toBeFalse()
        ->and($linker->suggest($parent, $other))->toBeFalse()
        ->and($linker->suggest($child, $other))->toBeFalse();

    expect($parent->fresh()->transfer_pair_id)->toBeNull()
        ->and($child->fresh()->transfer_pair_id)->toBeNull()
        ->and($other->fresh()->transfer_pair_id)->toBeNull()
        ->and($other->fresh()->suggested_pair_id)->toBeNull();
});

it('never offers a split row as a manual transfer leg', function () {
    $accountB = Account::factory()->for($this->user)->create();
    $parent = splitInto($this->user, splitRow($this->user, $accountB), [splitLine('60'), splitLine('40')]);
    $children = splitChildrenOf($parent);
    $real = splitRow($this->user, $accountB, ['amount' => 4000]);
    $credit = splitRow($this->user, $this->account, [
        'amount' => 4000,
        'direction' => TransactionDirection::Credit,
        'source' => TransactionSource::Redbark,
        'redbark_id' => 'rb_'.fake()->unique()->numerify('######'),
    ]);
    $linker = app(TransferLinker::class);

    $offered = $linker->candidatesFor($credit, includeRejected: true, windowDays: TransferLinker::MANUAL_WINDOW_DAYS)->pluck('id')->all();

    expect($offered)->toBe([$real->id])
        ->not->toContain($parent->id)
        ->not->toContain(...$children->modelKeys());

    $real->forceDelete();

    expect($linker->candidatesFor($credit, includeRejected: true, windowDays: TransferLinker::MANUAL_WINDOW_DAYS))->toBeEmpty();

    splitModal($this->user, $credit)
        ->set('transactionType', 'transfer')
        ->set('transferToAccountId', $accountB->id)
        ->call('save')
        ->assertHasNoErrors();

    $paired = Transaction::query()->where('user_id', $this->user->id)->whereNotNull('transfer_pair_id')->get();

    expect($paired)->toHaveCount(2)
        ->and($paired->pluck('account_id')->sort()->values()->all())->toBe(collect([$this->account->id, $accountB->id])->sort()->values()->all())
        ->and($paired->pluck('id')->all())->not->toContain($parent->id)
        ->not->toContain(...$children->modelKeys());
});

it('keeps the receipts of split lines when the split is edited or undone', function () {
    Storage::fake('local');
    $parent = splitInto($this->user, splitRow($this->user, $this->account), [splitLine('60'), splitLine('40')]);
    $receipt = splitReceiptOn(splitChildrenOf($parent)->first());

    splitModal($this->user, $parent)
        ->call('startSplit')
        ->set('splitLines', [splitLine('50'), splitLine('30'), splitLine('20')])
        ->call('saveSplit')
        ->assertHasNoErrors();

    expect(splitChildrenOf($parent))->toHaveCount(3)
        ->and($receipt->fresh()?->transaction_id)->toBe($parent->id)
        ->and(Storage::disk('local')->exists($receipt->path))->toBeTrue();

    $onLine = splitReceiptOn(splitChildrenOf($parent)->last());

    splitModal($this->user, $parent)->call('undoSplit')->assertHasNoErrors();

    expect(splitChildrenOf($parent))->toBeEmpty()
        ->and($receipt->fresh()?->transaction_id)->toBe($parent->id)
        ->and($onLine->fresh()?->transaction_id)->toBe($parent->id)
        ->and(Storage::disk('local')->exists($receipt->path))->toBeTrue()
        ->and(Storage::disk('local')->exists($onLine->path))->toBeTrue();
});

it('refuses to edit a split when its receipts would exceed the attachment limit', function () {
    Storage::fake('local');
    $parent = splitInto($this->user, splitRow($this->user, $this->account), [splitLine('60'), splitLine('40')]);
    $lines = splitChildrenOf($parent);

    foreach (range(1, 6) as $_) {
        splitReceiptOn($parent);
    }

    foreach (range(1, 5) as $_) {
        splitReceiptOn($lines->first());
    }

    splitModal($this->user, $parent)
        ->call('startSplit')
        ->set('splitLines', [splitLine('50'), splitLine('50')])
        ->call('saveSplit')
        ->assertHasErrors('splitLines');

    splitModal($this->user, $parent)->call('undoSplit')->assertHasErrors('splitLines');

    expect(splitChildrenOf($parent)->modelKeys())->toBe($lines->modelKeys())
        ->and(TransactionAttachment::query()->where('transaction_id', $parent->id)->count())->toBe(6)
        ->and(TransactionAttachment::query()->where('transaction_id', $lines->first()->id)->count())->toBe(5)
        ->and(splitCurrent($parent)->excluded)->toBeTrue();
});

it('refuses a modal edit of a split line that an undo trashed after the form loaded it', function () {
    $parent = splitInto($this->user, splitRow($this->user, $this->account), [splitLine('60'), splitLine('40')]);
    $line = splitChildrenOf($parent)->first();
    $armed = true;

    DB::listen(function (QueryExecuted $query) use (&$armed, $parent): void {
        if ($armed && str_contains($query->sql, 'select "id" from "accounts"')) {
            $armed = false;
            app(TransactionSplitter::class)->undo($parent->user_id, $parent->id);
        }
    });

    splitModal($this->user, $line)
        ->set('notes', 'edited after the undo')
        ->call('save')
        ->assertHasErrors('descriptionInput')
        ->assertNotDispatched('transaction-saved');

    expect($armed)->toBeFalse()
        ->and(Transaction::withTrashed()->where('user_id', $this->user->id)->count())->toBe(3)
        ->and($line->fresh()->notes)->toBeNull()
        ->and(Transaction::query()->where('user_id', $this->user->id)->whereNotNull('parent_transaction_id')->exists())->toBeFalse();
});
