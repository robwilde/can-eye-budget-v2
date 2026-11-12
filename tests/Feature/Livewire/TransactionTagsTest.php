<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\ImportSource;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\TransactionList;
use App\Livewire\TransactionModal;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\AuditEvent;
use App\Models\BnplOrder;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\Tags\TransactionTagger;
use App\Services\Transfers\TransferLinker;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-10-15');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function taggedRow(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->for($user)->for($account)->manual()->create(array_merge([
        'post_date' => '2026-10-10',
        'amount' => 1000,
        'direction' => TransactionDirection::Debit,
        'description' => 'ROW '.fake()->unique()->numerify('######'),
        'transfer_pair_id' => null,
        'category_id' => null,
    ], $overrides));
}

function taggedList(User $user, array $params = []): Testable
{
    return Livewire::actingAs($user)
        ->withQueryParams(array_merge(['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'], $params))
        ->test(TransactionList::class);
}

/** @return list<int> */
function tagIdsOn(Transaction $transaction): array
{
    return $transaction->tags()->orderBy('tags.id')->pluck('tags.id')->map(fn (mixed $id): int => (int) $id)->all();
}

function selectedRows(Testable $component, Transaction ...$rows): Testable
{
    return $component->set('selected', collect($rows)->mapWithKeys(fn (Transaction $row): array => [$row->id => true])->all());
}

test('changes tags in bulk only when asked and clears them on an empty list', function () {
    $tagA = Tag::factory()->for($this->user)->create();
    $category = Category::factory()->for($this->user)->create();
    $row = taggedRow($this->user, $this->account);
    $row->tags()->attach($tagA->id);

    selectedRows(taggedList($this->user), $row)
        ->set('bulkCategoryId', (string) $category->id)
        ->call('applyCategoryToSelection');

    expect(tagIdsOn($row))->toBe([$tagA->id]);

    selectedRows(taggedList($this->user), $row)
        ->set('bulkEditInclude', ['notes'])
        ->set('bulkEditNotes', 'edited')
        ->call('bulkEdit');

    $current = Transaction::findCurrentVersion($row->id, $this->user->id);

    expect($current->id)->not->toBe($row->id)
        ->and(tagIdsOn($current))->toBe([$tagA->id]);

    selectedRows(taggedList($this->user), $current)
        ->set('bulkEditInclude', ['tags'])
        ->set('bulkEditTagIds', [])
        ->call('bulkEdit')
        ->assertHasNoErrors();

    expect(tagIdsOn(Transaction::findCurrentVersion($row->id, $this->user->id)))->toBe([]);
});

test('sets the chosen tags in bulk and locks the field against sync', function () {
    $tagA = Tag::factory()->for($this->user)->create();
    $tagB = Tag::factory()->for($this->user)->create();
    $rows = collect(range(1, 3))->map(fn (): Transaction => taggedRow($this->user, $this->account));
    $rows->first()->tags()->attach($tagA->id);

    selectedRows(taggedList($this->user), ...$rows)
        ->set('bulkEditInclude', ['tags'])
        ->set('bulkEditTagIds', [(string) $tagB->id])
        ->call('bulkEdit');

    $rows->each(function (Transaction $row) use ($tagB): void {
        $fresh = $row->refresh();

        expect(tagIdsOn($fresh))->toBe([$tagB->id])
            ->and($fresh->locked_fields)->toBe(['tags']);
    });
});

test('applies transfer tags to both legs', function () {
    $savings = Tag::factory()->for($this->user)->create(['name' => 'Savings']);
    $to = Account::factory()->for($this->user)->create([
        'balance' => 0,
        'balance_source' => ImportSource::Manual,
        'is_tracked' => true,
    ]);
    AccountValuation::factory()->for($this->account)->opening()->create(['valued_on' => '2026-10-01', 'balance' => 500000]);
    AccountValuation::factory()->for($to)->opening()->create(['valued_on' => '2026-10-01', 'balance' => 0]);
    app(AccountBalanceHistoryBuilder::class)->rebuild($this->account);
    app(AccountBalanceHistoryBuilder::class)->rebuild($to);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-10-10')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '100 moving money')
        ->set('accountId', $this->account->id)
        ->set('transferToAccountId', $to->id)
        ->set('tagIds', [(string) $savings->id])
        ->call('save')
        ->assertHasNoErrors();

    $debit = Transaction::query()->where('user_id', $this->user->id)->where('account_id', $this->account->id)->whereNotNull('transfer_pair_id')->sole();
    $credit = $debit->transferPair;

    expect(tagIdsOn($debit))->toBe([$savings->id])
        ->and(tagIdsOn($credit))->toBe([$savings->id]);
});

test('changes the tags of an edited transaction from the modal', function () {
    $tagA = Tag::factory()->for($this->user)->create();
    $tagB = Tag::factory()->for($this->user)->create();
    $row = taggedRow($this->user, $this->account);
    $row->tags()->attach($tagA->id);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->call('openForEdit', $row->id)
        ->assertSet('tagIds', [$tagA->id])
        ->set('tagIds', [(string) $tagB->id])
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::findCurrentVersion($row->id, $this->user->id);

    expect(tagIdsOn($current))->toBe([$tagB->id])
        ->and($current->locked_fields)->toContain('tags');
});

test('lists and totals a multi-tag match once', function () {
    $tagA = Tag::factory()->for($this->user)->create();
    $tagB = Tag::factory()->for($this->user)->create();
    $both = taggedRow($this->user, $this->account, ['amount' => 2500]);
    $both->tags()->attach([$tagA->id, $tagB->id]);
    taggedRow($this->user, $this->account);

    $component = taggedList($this->user, ['tags' => [(string) $tagA->id, (string) $tagB->id]]);

    expect($component->viewData('transactions')->total())->toBe(1)
        ->and(collect($component->viewData('transactions')->items())->pluck('id')->all())->toBe([$both->id])
        ->and($component->viewData('totals'))->toMatchArray(['count' => 1, 'expenses' => 2500]);
});

test('ignores another users tags when assigning', function () {
    $mine = Tag::factory()->for($this->user)->create();
    $foreign = Tag::factory()->for(User::factory()->create())->create();
    $row = taggedRow($this->user, $this->account);
    $row->tags()->attach($mine->id);

    selectedRows(taggedList($this->user), $row)
        ->set('bulkEditInclude', ['tags'])
        ->set('bulkEditTagIds', [(string) $foreign->id])
        ->call('bulkEdit');

    expect(tagIdsOn($row))->toBe([$mine->id])
        ->and($foreign->transactions()->count())->toBe(0);

    selectedRows(taggedList($this->user), $row)
        ->set('bulkEditInclude', ['tags'])
        ->set('bulkEditTagIds', [(string) $foreign->id, (string) $mine->id])
        ->call('bulkEdit');

    expect(tagIdsOn($row))->toBe([$mine->id])
        ->and($foreign->transactions()->count())->toBe(0);
});

test('filters untagged transactions', function () {
    $tag = Tag::factory()->for($this->user)->create();
    $tagged = taggedRow($this->user, $this->account);
    $tagged->tags()->attach($tag->id);
    $untagged = taggedRow($this->user, $this->account);

    $only = taggedList($this->user, ['tags' => ['untagged']]);
    $either = taggedList($this->user, ['tags' => ['untagged', (string) $tag->id]]);

    expect(collect($only->viewData('transactions')->items())->pluck('id')->all())->toBe([$untagged->id])
        ->and($either->viewData('transactions')->total())->toBe(2);
});

test('records an audit event for a bulk tag', function () {
    $tag = Tag::factory()->for($this->user)->create();
    $row = taggedRow($this->user, $this->account);

    selectedRows(taggedList($this->user), $row)
        ->set('bulkEditInclude', ['tags'])
        ->set('bulkEditTagIds', [(string) $tag->id])
        ->call('bulkEdit');

    $event = AuditEvent::query()->where('action', 'livewire.transaction-list.bulkEdit')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and(tagIdsOn($row))->toBe([$tag->id]);
});

test('toggles a tag on a row, and on both legs of a transfer, in the order requested', function () {
    $tag = Tag::factory()->for($this->user)->create();
    $other = Account::factory()->for($this->user)->create();
    $debit = taggedRow($this->user, $this->account);
    $credit = taggedRow($this->user, $other, ['direction' => TransactionDirection::Credit, 'transfer_pair_id' => $debit->id]);
    $debit->update(['transfer_pair_id' => $credit->id]);
    $single = taggedRow($this->user, $this->account);

    $component = taggedList($this->user)
        ->call('toggleTag', $single->id, $tag->id)
        ->call('toggleTag', $single->id, $tag->id)
        ->call('toggleTag', $single->id, $tag->id)
        ->call('toggleTag', $debit->id, $tag->id);

    expect(tagIdsOn($single))->toBe([$tag->id])
        ->and($single->refresh()->locked_fields)->toBe(['tags'])
        ->and(tagIdsOn($debit))->toBe([$tag->id])
        ->and(tagIdsOn($credit))->toBe([$tag->id]);

    $component->call('toggleTag', $credit->id, $tag->id);

    expect(tagIdsOn($debit))->toBe([])
        ->and(tagIdsOn($credit))->toBe([]);
});

test('refuses to toggle another users tag or transaction', function () {
    $foreignTag = Tag::factory()->for(User::factory()->create())->create();
    $foreignRow = Transaction::factory()->create();
    $mine = Tag::factory()->for($this->user)->create();
    $row = taggedRow($this->user, $this->account);

    taggedList($this->user)
        ->call('toggleTag', $row->id, $foreignTag->id)
        ->call('toggleTag', $foreignRow->id, $mine->id);

    expect(tagIdsOn($row))->toBe([])
        ->and(tagIdsOn($foreignRow))->toBe([]);
});

test('keeps tags when a tagged transaction is edited', function () {
    $tag = Tag::factory()->for($this->user)->create();
    $row = taggedRow($this->user, $this->account);
    $row->tags()->attach($tag->id);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->call('openForEdit', $row->id)
        ->set('notes', 'edited')
        ->call('save')
        ->assertHasNoErrors();

    $current = Transaction::findCurrentVersion($row->id, $this->user->id);

    expect($current->id)->not->toBe($row->id)
        ->and($current->notes)->toBe('edited')
        ->and(tagIdsOn($current))->toBe([$tag->id])
        ->and($current->locked_fields)->not->toContain('tags');
});

function throwingTagger(): void
{
    app()->bind(TransactionTagger::class, fn (): object => new class
    {
        public function replace(): never
        {
            throw new RuntimeException('tagging failed');
        }
    });
}

test('rolls back the save when tagging fails', function () {
    $tag = Tag::factory()->for($this->user)->create();
    $existing = taggedRow($this->user, $this->account);
    $before = Transaction::query()->count();
    throwingTagger();

    $create = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-10-10')
        ->set('descriptionInput', '12 coffee')
        ->set('accountId', $this->account->id)
        ->set('tagIds', [(string) $tag->id]);

    expect(fn () => $create->call('save'))->toThrow(RuntimeException::class)
        ->and(Transaction::query()->count())->toBe($before);

    $edit = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->call('openForEdit', $existing->id)
        ->set('notes', 'edited')
        ->set('tagIds', [(string) $tag->id]);

    expect(fn () => $edit->call('save'))->toThrow(RuntimeException::class)
        ->and(Transaction::query()->count())->toBe($before)
        ->and(Transaction::findCurrentVersion($existing->id, $this->user->id)->id)->toBe($existing->id)
        ->and($existing->refresh()->notes)->toBeNull();
});

test('ignores a malformed tag filter in the url', function () {
    $row = taggedRow($this->user, $this->account);

    $component = taggedList($this->user, ['tags' => [['id' => 1], 'junk', '']]);

    expect($component->viewData('transactions')->total())->toBe(1)
        ->and($component->get('tags'))->toBe([]);
});

test('puts the tags of a converted transaction on both transfer legs', function () {
    $tag = Tag::factory()->for($this->user)->create();
    $to = Account::factory()->for($this->user)->create(['is_tracked' => true]);
    $row = taggedRow($this->user, $this->account);
    $row->tags()->attach($tag->id);

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->call('openForEdit', $row->id)
        ->set('transactionType', 'transfer')
        ->set('transferToAccountId', $to->id)
        ->call('save')
        ->assertHasNoErrors();

    $debit = Transaction::findCurrentVersion($row->id, $this->user->id);

    expect($debit->transfer_pair_id)->not->toBeNull()
        ->and(tagIdsOn($debit))->toBe([$tag->id])
        ->and(tagIdsOn($debit->transferPair))->toBe([$tag->id]);
});

test('keeps the tag lock on both transfer legs after a mixed bulk edit', function () {
    $tag = Tag::factory()->for($this->user)->create();
    $other = Account::factory()->for($this->user)->create();
    $debit = taggedRow($this->user, $this->account);
    $credit = taggedRow($this->user, $other, ['direction' => TransactionDirection::Credit, 'transfer_pair_id' => $debit->id]);
    $debit->update(['transfer_pair_id' => $credit->id]);

    selectedRows(taggedList($this->user), $debit, $credit)
        ->set('bulkEditInclude', ['notes', 'tags'])
        ->set('bulkEditNotes', 'both')
        ->set('bulkEditTagIds', [(string) $tag->id])
        ->call('bulkEdit');

    foreach ([$debit, $credit] as $leg) {
        $current = Transaction::findCurrentVersion($leg->id, $this->user->id);

        expect($current->notes)->toBe('both')
            ->and(tagIdsOn($current))->toBe([$tag->id])
            ->and($current->locked_fields)->toContain('tags');
    }
});

test('keeps a bank-feed transfer linked when tagging fails while unlinking it', function () {
    $tag = Tag::factory()->for($this->user)->create();
    $other = Account::factory()->for($this->user)->create();
    $feed = Transaction::factory()->for($this->user)->for($this->account)->fromRedbark()->create([
        'post_date' => '2026-10-10',
        'amount' => 1000,
        'direction' => TransactionDirection::Debit,
        'transfer_pair_id' => null,
        'category_id' => null,
    ]);
    $credit = taggedRow($this->user, $other, ['direction' => TransactionDirection::Credit, 'transfer_pair_id' => $feed->id]);
    $feed->update(['transfer_pair_id' => $credit->id]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->call('openForEdit', $feed->id)
        ->set('transactionType', 'expense')
        ->set('tagIds', [(string) $tag->id]);

    throwingTagger();

    expect(fn () => $component->call('save'))->toThrow(RuntimeException::class)
        ->and($feed->refresh()->transfer_pair_id)->toBe($credit->id)
        ->and($credit->refresh()->transfer_pair_id)->toBe($feed->id);
});

/** @return list<string> */
function createQueryOrder(Closure $save): array
{
    $order = [];

    DB::listen(function (QueryExecuted $query) use (&$order): void {
        $sql = mb_strtolower($query->sql);

        if (preg_match('/^select\s+.?id.?\s+from\s+.?accounts.?\s+where\s+.?id.?\s+in\b/', $sql) === 1) {
            $order[] = 'lock';
        } elseif (str_starts_with($sql, 'select') && str_contains($sql, 'idempotency_key')) {
            $order[] = 'idempotency-read';
        }
    });

    $save();

    return $order;
}

test('locks the account before the idempotency read on a manual create', function () {
    $order = createQueryOrder(fn () => Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-10-10')
        ->set('descriptionInput', '12 coffee')
        ->set('accountId', $this->account->id)
        ->call('save')
        ->assertHasNoErrors());

    expect($order)->toContain('idempotency-read')
        ->and($order[0])->toBe('lock');
});

test('locks both accounts before the idempotency read on a transfer create', function () {
    $to = Account::factory()->for($this->user)->create([
        'balance' => 0,
        'balance_source' => ImportSource::Manual,
        'is_tracked' => true,
    ]);

    $order = createQueryOrder(fn () => Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-10-10')
        ->set('transactionType', 'transfer')
        ->set('descriptionInput', '100 moving money')
        ->set('accountId', $this->account->id)
        ->set('transferToAccountId', $to->id)
        ->call('save')
        ->assertHasNoErrors());

    expect($order)->toContain('idempotency-read')
        ->and($order[0])->toBe('lock');
});

test('tags the current transfer partner when it was versioned during the save', function () {
    $tag = Tag::factory()->for($this->user)->create();
    $other = Account::factory()->for($this->user)->create();
    $feed = Transaction::factory()->for($this->user)->for($this->account)->fromRedbark()->create([
        'post_date' => '2026-10-10',
        'amount' => 1000,
        'direction' => TransactionDirection::Debit,
        'transfer_pair_id' => null,
        'category_id' => null,
    ]);
    $credit = taggedRow($this->user, $other, ['direction' => TransactionDirection::Credit, 'transfer_pair_id' => $feed->id]);
    $feed->update(['transfer_pair_id' => $credit->id]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->call('openForEdit', $feed->id)
        ->set('tagIds', [(string) $tag->id]);

    $creditCurrent = null;
    $armed = true;

    Event::listen('eloquent.creating: '.Transaction::class, function () use (&$armed, &$creditCurrent, $credit): void {
        if (! $armed) {
            return;
        }

        $armed = false;
        $creditCurrent = $credit->createChild();
        app(TransferLinker::class)->followVersion($credit, $creditCurrent);
    });

    $component->call('save')->assertHasNoErrors();

    $child = Transaction::findCurrentVersion($feed->id, $this->user->id);

    expect($child->transfer_pair_id)->toBe($creditCurrent->id)
        ->and(tagIdsOn($child))->toBe([$tag->id])
        ->and(tagIdsOn($creditCurrent))->toBe([$tag->id])
        ->and(tagIdsOn($credit))->toBe([]);
});

test('puts the chosen tags on every instalment of a split BNPL debit', function () {
    $tag = Tag::factory()->for($this->user)->create();

    foreach (range(1, 3) as $ignored) {
        $plan = PlannedTransaction::factory()->for($this->user)->for($this->account)->create([
            'category_id' => null,
            'amount' => 1861,
            'direction' => TransactionDirection::Debit,
            'description' => 'Afterpay - '.fake()->company(),
            'start_date' => '2026-08-07',
            'until_date' => '2026-09-18',
            'frequency' => RecurrenceFrequency::Every2Weeks,
            'is_active' => true,
        ]);

        BnplOrder::factory()->autoApproved()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'planned_transaction_id' => $plan->id,
            'instalment_amount' => 1861,
            'first_due_date' => '2026-08-07',
            'last_due_date' => '2026-09-18',
        ]);
    }

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-09-04')
        ->set('descriptionInput', '55.83 VISA -Afterpay afterpay.com AU 145377')
        ->set('accountId', $this->account->id)
        ->set('tagIds', [(string) $tag->id])
        ->call('save')
        ->assertHasNoErrors();

    $instalments = Transaction::query()->current()->where('user_id', $this->user->id)->get();

    expect($instalments)->toHaveCount(3)
        ->and($instalments->every(fn (Transaction $row): bool => tagIdsOn($row) === [$tag->id]))->toBeTrue();
});

test('hides the tag field while a planned transaction is converted to entered', function () {
    Tag::factory()->for($this->user)->create();
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->create();

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('open-transaction-modal', date: '2026-10-10')
        ->assertSeeHtml('data-test="modal-tags"');

    Livewire::actingAs($this->user)
        ->test(TransactionModal::class)
        ->dispatch('edit-planned-transaction', id: $planned->id)
        ->set('mode', 'enter')
        ->assertDontSeeHtml('data-test="modal-tags"');
});
