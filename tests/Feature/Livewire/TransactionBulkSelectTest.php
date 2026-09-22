<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\TransactionDirection;
use App\Events\TransactionCategoryUpdated;
use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->category = Category::factory()->create(['is_hidden' => false]);
});

function bulkTxn(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'NETFLIX.COM',
        'merchant_name' => null,
        'clean_description' => null,
        'category_id' => null,
        'amount' => 1899,
        'direction' => TransactionDirection::Debit,
        'post_date' => CarbonImmutable::parse('2026-06-10'),
        'transfer_pair_id' => null,
    ], $overrides));
}

it('applies a category to exactly the ticked rows and nothing else', function () {
    $a = bulkTxn($this->user, $this->account);
    $b = bulkTxn($this->user, $this->account);
    $untouched = bulkTxn($this->user, $this->account);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$a->id => true, $b->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection')
        ->assertHasNoErrors();

    expect($a->fresh()->category_id)->toBe($this->category->id)
        ->and($b->fresh()->category_id)->toBe($this->category->id)
        ->and($untouched->fresh()->category_id)->toBeNull();
});

it('stamps a bulk assignment as manual so rules cannot overwrite it later', function () {
    $txn = bulkTxn($this->user, $this->account);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$txn->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    expect($txn->fresh()->category_source)->toBe(CategorySource::Manual);
});

it('fires the category-updated event per row rather than a silent mass update', function () {
    $a = bulkTxn($this->user, $this->account);
    $b = bulkTxn($this->user, $this->account);

    Event::fake([TransactionCategoryUpdated::class]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$a->id => true, $b->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    Event::assertDispatchedTimes(TransactionCategoryUpdated::class, 2);
});

it('ignores a forged id belonging to another user', function () {
    $intruder = User::factory()->create();
    $intruderAccount = Account::factory()->for($intruder)->create();
    $theirs = bulkTxn($intruder, $intruderAccount);

    $mine = bulkTxn($this->user, $this->account);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$mine->id => true, $theirs->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    expect($mine->fresh()->category_id)->toBe($this->category->id)
        ->and($theirs->fresh()->category_id)->toBeNull();
});

it('refuses to categorise a transfer even when its id is selected', function () {
    $other = bulkTxn($this->user, $this->account);
    $transfer = bulkTxn($this->user, $this->account, ['transfer_pair_id' => $other->id]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$transfer->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    expect($transfer->fresh()->category_id)->toBeNull();
});

it('refuses to categorise a split transaction even when its id is selected', function () {
    $splitCategory = Category::factory()->create(['is_hidden' => false]);
    $txn = bulkTxn($this->user, $this->account);
    $txn->splits()->create([
        'category_id' => $splitCategory->id,
        'amount' => 1899,
        'position' => 1,
    ]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$txn->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    expect($txn->fresh()->category_id)->toBeNull();
});

it('rejects an empty or hidden category without writing anything', function () {
    $hidden = Category::factory()->create(['is_hidden' => true]);
    $txn = bulkTxn($this->user, $this->account);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$txn->id => true])
        ->set('bulkCategoryId', '')
        ->call('applyCategoryToSelection');

    expect($component->get('bulkError'))->toBe('Choose a category first.')
        ->and($txn->fresh()->category_id)->toBeNull();

    $component->set('bulkCategoryId', (string) $hidden->id)
        ->call('applyCategoryToSelection');

    expect($component->get('bulkError'))->toBe('That category is not available.')
        ->and($txn->fresh()->category_id)->toBeNull();
});

it('survives pagination so a selection made on page one still applies', function () {
    // 30 rows across two pages of 25; pick one from each page.
    $rows = collect(range(1, 30))->map(fn (int $i): Transaction => bulkTxn($this->user, $this->account, [
        'post_date' => CarbonImmutable::parse('2026-06-10')->subDays($i % 5),
    ]));

    $first = $rows->first();
    $last = $rows->last();

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$first->id => true])
        ->call('gotoPage', 2)
        ->set('selected', [$first->id => true, $last->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    expect($first->fresh()->category_id)->toBe($this->category->id)
        ->and($last->fresh()->category_id)->toBe($this->category->id);
});

it('select-all-matching writes every eligible row for the snapshot, not just the page', function () {
    collect(range(1, 30))->each(fn () => bulkTxn($this->user, $this->account));

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->call('selectAllMatching')
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    expect(Transaction::where('user_id', $this->user->id)->whereNull('category_id')->count())->toBe(0);
});

it('select-all-matching honours the snapshot taken at click time, not later filter changes', function () {
    $inScope = bulkTxn($this->user, $this->account, ['direction' => TransactionDirection::Debit]);
    $outOfScope = bulkTxn($this->user, $this->account, ['direction' => TransactionDirection::Credit]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('direction', 'outgoing')
        ->call('selectAllMatching')
        // The user changes their mind about the filter after selecting. The
        // write must still cover the snapshot they were shown a count for.
        ->set('direction', 'incoming')
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    expect($inScope->fresh()->category_id)->toBe($this->category->id)
        ->and($outOfScope->fresh()->category_id)->toBeNull();
});

it('excludes transfers and splits from the select-all-matching count and write', function () {
    $plain = bulkTxn($this->user, $this->account);
    $other = bulkTxn($this->user, $this->account);
    $transfer = bulkTxn($this->user, $this->account, ['transfer_pair_id' => $other->id]);

    $splitCategory = Category::factory()->create(['is_hidden' => false]);
    $split = bulkTxn($this->user, $this->account);
    $split->splits()->create([
        'category_id' => $splitCategory->id,
        'amount' => 1899,
        'position' => 1,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        // The 'all' categorised filter does not itself exclude splits, so this
        // leaves eligibleForBulk() as the only thing standing between the split
        // row and the write.
        ->set('categorised', 'all')
        ->call('selectAllMatching');

    // plain + other are eligible; the transfer and the split row are not.
    expect($component->viewData('selectionCount'))->toBe(2);

    $component->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    expect($plain->fresh()->category_id)->toBe($this->category->id)
        ->and($transfer->fresh()->category_id)->toBeNull()
        ->and($split->fresh()->category_id)->toBeNull();
});

it('selects a whole merchant cluster in one action', function () {
    foreach ([4829, 5561, 9903] as $ref) {
        bulkTxn($this->user, $this->account, ['description' => "PAYPAL *STEAM {$ref}"]);
    }
    $unrelated = bulkTxn($this->user, $this->account, ['description' => 'NETFLIX.COM']);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant')
        ->call('selectCluster', 'PAYPAL STEAM');

    expect($component->viewData('selectionCount'))->toBe(3);

    $component->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    expect(Transaction::where('merchant_key', 'PAYPAL STEAM')->whereNull('category_id')->count())->toBe(0)
        ->and($unrelated->fresh()->category_id)->toBeNull();
});

it('drops a filter-wide scope as soon as the user hand-picks a row', function () {
    bulkTxn($this->user, $this->account);
    $one = bulkTxn($this->user, $this->account);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('selectAllMatching');

    expect($component->viewData('selectionCount'))->toBe(2);

    $component->set('selected', [$one->id => true]);

    expect($component->get('bulkScope'))->toBeNull()
        ->and($component->viewData('selectionCount'))->toBe(1);
});

it('clears the selection after a successful apply and reports the count', function () {
    $txn = bulkTxn($this->user, $this->account);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$txn->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    expect($component->get('selected'))->toBe([])
        ->and($component->get('bulkScope'))->toBeNull()
        ->and($component->get('bulkNotice'))->toBe('Categorised 1 transaction.');
});

it('toggles every eligible row on screen and back off again', function () {
    bulkTxn($this->user, $this->account);
    bulkTxn($this->user, $this->account);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class);

    $ids = $component->viewData('pageEligibleIds');

    $component->call('toggleVisible', $ids, true);
    expect($component->viewData('selectionCount'))->toBe(2);

    $component->call('toggleVisible', $ids, false);
    expect($component->viewData('selectionCount'))->toBe(0);
});

it('keeps excluded rows out of the on-screen eligible set', function () {
    $other = bulkTxn($this->user, $this->account);
    bulkTxn($this->user, $this->account, ['transfer_pair_id' => $other->id]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class);

    // $other plus the transfer are on screen; only $other is eligible.
    expect($component->viewData('pageEligibleIds'))->toBe([$other->id]);
});

it('shows the success notice even when the write empties the filtered list', function () {
    foreach (range(1, 3) as $ignored) {
        bulkTxn($this->user, $this->account);
    }

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->call('selectAllMatching')
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection')
        // Categorising the last uncategorised rows empties the list, which is
        // precisely when the confirmation matters. It must not be swallowed by
        // the empty-state branch.
        ->assertSee('Categorised 3 transactions.')
        ->assertSeeHtml('data-testid="bulk-notice"');
});

it('refuses a client attempt to rewrite the bulk scope', function () {
    bulkTxn($this->user, $this->account, ['description' => 'PAYPAL *STEAM 4829']);
    bulkTxn($this->user, $this->account, ['description' => 'PAYPAL *STEAM 5561']);
    $outsideCluster = bulkTxn($this->user, $this->account, ['description' => 'NETFLIX.COM']);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant')
        ->call('selectCluster', 'PAYPAL STEAM');

    expect($component->viewData('selectionCount'))->toBe(2);

    // A forged merchantKey used to drop the merchant predicate and widen the
    // write to every row matching the filters. The property is locked, so the
    // client cannot reach it at all.
    expect(fn () => $component->set('bulkScope', [
        'filters' => ['categorised' => 'uncategorised'],
        'merchantKey' => ['PAYPAL STEAM'],
    ]))->toThrow(CannotUpdateLockedPropertyException::class);

    // Then actually write, so this asserts containment rather than the absence
    // of a write that never happened.
    $component->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    expect($outsideCluster->fresh()->category_id)->toBeNull()
        ->and(Transaction::where('merchant_key', 'PAYPAL STEAM')->whereNull('category_id')->count())->toBe(0);
});

it('keeps rows with no merchant key out of the merchant-mode select-all', function () {
    bulkTxn($this->user, $this->account, ['description' => 'PAYPAL *STEAM 4829']);
    bulkTxn($this->user, $this->account, ['description' => 'PAYPAL *STEAM 5561']);

    // merchant_key is nullable and its backfill is a deploy step, so an
    // unclustered row is a real possibility. It appears in no cluster on
    // screen, so "select all matching this filter" must not write it.
    $unclustered = bulkTxn($this->user, $this->account);
    $unclustered->forceFill(['merchant_key' => null])->saveQuietly();

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant')
        ->call('selectAllMatching');

    expect($component->viewData('matchingCount'))->toBe(2)
        ->and($component->viewData('selectionCount'))->toBe(2);

    $component->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    expect($unclustered->fresh()->category_id)->toBeNull();
});

it('labels a merchant cluster by the rows a bulk write can actually touch', function () {
    $a = bulkTxn($this->user, $this->account, ['description' => 'PAYPAL *STEAM 4829']);
    bulkTxn($this->user, $this->account, ['description' => 'PAYPAL *STEAM 5561']);
    // Both legs of a transfer share a description, so a transfer clusters here
    // — but eligibleForBulk() refuses it, so the button must not count it.
    bulkTxn($this->user, $this->account, [
        'description' => 'PAYPAL *STEAM 9903',
        'transfer_pair_id' => $a->id,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant');

    $component->assertSee('Select all 2 in this merchant')
        ->assertDontSee('Select all 3 in this merchant');

    $component->call('selectCluster', 'PAYPAL STEAM');

    expect($component->viewData('selectionCount'))->toBe(2);
});

it('reports only the rows a re-apply actually changed', function () {
    // Already carries both the category and the provenance a bulk apply would
    // write, so the save is a true no-op rather than a provenance-only update.
    $already = bulkTxn($this->user, $this->account, [
        'category_id' => $this->category->id,
        'category_source' => CategorySource::Manual,
    ]);
    $fresh = bulkTxn($this->user, $this->account);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$already->id => true, $fresh->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    // A no-op save must not inflate the number reported back to the user.
    expect($component->get('bulkNotice'))->toBe('Categorised 1 transaction.');
});

it('counts a re-apply that only relocks rule-assigned rows as manual', function () {
    // The "lock these in so rules stop overwriting them" workflow: filter to a
    // category, select all, apply that same category. No category_id changes,
    // but every rule-assigned row is restamped Manual — a real write that must
    // not report "Categorised 0 transactions."
    $ruleAssigned = collect(range(1, 3))->map(fn (): Transaction => bulkTxn($this->user, $this->account, [
        'category_id' => $this->category->id,
        'category_source' => CategorySource::Rule,
    ]));

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'categorised')
        ->set('category', $this->category->id)
        ->call('selectAllMatching')
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    expect($component->get('bulkNotice'))->toBe('Categorised 3 transactions.');

    $ruleAssigned->each(function (Transaction $transaction): void {
        expect($transaction->fresh()->category_source)->toBe(CategorySource::Manual);
    });
});

it('leaves an unselected planned sibling untouched while a single edit still propagates', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->create();

    $selected = bulkTxn($this->user, $this->account, ['planned_transaction_id' => $planned->id]);
    $other = bulkTxn($this->user, $this->account);
    // Shares the planned group with $selected, and is a transfer — the list
    // renders it with a disabled checkbox and a stated reason.
    $excludedSibling = bulkTxn($this->user, $this->account, [
        'planned_transaction_id' => $planned->id,
        'transfer_pair_id' => $other->id,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$selected->id => true, $excludedSibling->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    expect($component->get('bulkNotice'))->toBe('Categorised 1 transaction.');

    // Exactly the ticked, eligible row. The fan-out across
    // planned_transaction_id is opted out of for bulk, so the row the page
    // refused to select is not rewritten behind the user's back.
    expect($selected->fresh()->category_id)->toBe($this->category->id)
        ->and($excludedSibling->fresh()->category_id)->toBeNull()
        ->and($planned->fresh()->category_id)->toBeNull();

    // The opt-out is scoped to the bulk write: a single-row edit still fans
    // out, so planned grouping is unchanged for every other writer.
    $selected->fresh()->update(['category_id' => $this->category->id, 'category_source' => null]);
    $sibling = bulkTxn($this->user, $this->account, ['planned_transaction_id' => $planned->id]);
    $secondCategory = Category::factory()->create(['is_hidden' => false]);

    $selected->fresh()->update(['category_id' => $secondCategory->id]);

    expect($sibling->fresh()->category_id)->toBe($secondCategory->id)
        ->and($planned->fresh()->category_id)->toBe($secondCategory->id);
});

it('does not re-run the planned fan-out once per selected sibling', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->create();

    $rows = collect(range(1, 5))->map(fn (): Transaction => bulkTxn($this->user, $this->account, [
        'planned_transaction_id' => $planned->id,
    ]));

    $writes = 0;
    DB::listen(function ($query) use (&$writes): void {
        // Scoped to the transactions table so an unrelated write elsewhere in
        // the request cannot be misattributed to the planned fan-out.
        if (str_starts_with(mb_strtolower($query->sql), 'update')
            && str_contains($query->sql, 'transactions')) {
            $writes++;
        }
    });

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', $rows->mapWithKeys(fn (Transaction $t): array => [$t->id => true])->all())
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    // One UPDATE per selected row. With the fan-out live each save also
    // mass-updated the other four plus the planned row, which is O(N²).
    expect($writes)->toBe(5);
});

it('returns to the first page so the remaining rows stay visible after a write', function () {
    // 30 uncategorised rows: two pages of 25. Categorise the second page and
    // the paginator would otherwise stay on a page that no longer exists.
    $rows = collect(range(1, 30))->map(fn (): Transaction => bulkTxn($this->user, $this->account));
    $lastPageRow = $rows->last();

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->call('gotoPage', 2)
        ->set('selected', [$lastPageRow->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    $transactions = $component->viewData('transactions');

    expect($transactions->currentPage())->toBe(1)
        ->and($transactions->count())->toBe(25)
        ->and($transactions->total())->toBe(29);
});

it('states the partial page selection in the control label', function () {
    $a = bulkTxn($this->user, $this->account);
    bulkTxn($this->user, $this->account);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class);

    $component->assertSee('Select this page (2)');

    $component->set('selected', [$a->id => true]);

    // Some but not all: the control must not read as an untouched page.
    $component->assertSee('Select the other 1 (1 of 2 selected)')
        ->assertDontSee('Select this page (2)');

    $component->call('toggleVisible', $component->viewData('pageEligibleIds'), true);

    $component->assertSee('Clear these 2');
});

it('does not claim a cluster selection over a different expanded cluster', function () {
    foreach ([4829, 5561] as $ref) {
        bulkTxn($this->user, $this->account, ['description' => "PAYPAL *STEAM {$ref}"]);
    }
    bulkTxn($this->user, $this->account, ['description' => 'NETFLIX.COM']);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('groupMode', 'merchant')
        ->call('selectCluster', 'PAYPAL STEAM')
        ->call('toggleCluster', 'PAYPAL STEAM');

    expect($component->viewData('scopeCoversScreen'))->toBeTrue();
    $component->assertSee('Clear these 2');

    // Expanding a different cluster does not clear the scope, so the control
    // must stop claiming the rows on screen are selected — clicking it calls
    // toggleVisible(), which would silently discard the real selection.
    $component->call('toggleCluster', 'NETFLIX.COM');

    expect($component->viewData('scopeCoversScreen'))->toBeFalse()
        ->and($component->viewData('selectionCount'))->toBe(2);
    $component->assertSee('Select these 1');
});

it('stops claiming a filter-wide selection once the filters move off the snapshot', function () {
    bulkTxn($this->user, $this->account, ['direction' => TransactionDirection::Debit]);
    bulkTxn($this->user, $this->account, ['direction' => TransactionDirection::Debit]);
    bulkTxn($this->user, $this->account, ['direction' => TransactionDirection::Credit]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('direction', 'outgoing')
        ->call('selectAllMatching');

    expect($component->viewData('scopeCoversScreen'))->toBeTrue();

    $component->set('direction', 'incoming');

    // The snapshot still governs the write — that is deliberate — but the
    // on-screen control must not report those unrelated rows as selected.
    expect($component->viewData('scopeCoversScreen'))->toBeFalse()
        ->and($component->viewData('selectionCount'))->toBe(2);
    $component->assertSee('Select this page (1)');
});

it('leaves an unselected ordinary planned sibling untouched', function () {
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->create();

    $selected = bulkTxn($this->user, $this->account, ['planned_transaction_id' => $planned->id]);
    // Fully eligible and rendered with an ENABLED checkbox — the user simply
    // chose not to tick it. This is the dominant containment case, not the
    // transfer/split one.
    $unticked = bulkTxn($this->user, $this->account, ['planned_transaction_id' => $planned->id]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$selected->id => true])
        ->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    expect($selected->fresh()->category_id)->toBe($this->category->id)
        ->and($unticked->fresh()->category_id)->toBeNull()
        ->and($planned->fresh()->category_id)->toBeNull();
});

it('marks an excluded row as disabled with a valid aria value', function () {
    $other = bulkTxn($this->user, $this->account);
    $transfer = bulkTxn($this->user, $this->account, ['transfer_pair_id' => $other->id]);

    $html = Livewire::actingAs($this->user)->test(TransactionList::class)->html();

    // Flux folds a static aria-disabled="true" into aria-disabled="aria-disabled",
    // which is not a valid ARIA value and is silently ignored by assistive tech.
    expect($html)->toContain('aria-disabled="true"')
        ->and($html)->not->toContain('aria-disabled="aria-disabled"')
        ->and($html)->toContain('data-testid="select-excluded-'.$transfer->id.'"');
});

it('drops an expanded cluster the paginator has moved past out of the page-level selection', function () {
    $expanded = collect(range(1, 3))
        ->map(fn (): Transaction => bulkTxn($this->user, $this->account, ['description' => 'AAA MERCHANT']));

    // 30 single-row clusters push the cluster paginator past its 25-per-page
    // limit. merchant_key strips digits, so the suffixes have to be letters or
    // the fillers collapse into one cluster.
    for ($i = 0; $i < 30; $i++) {
        bulkTxn($this->user, $this->account, [
            'description' => sprintf('FILLER %s%s', chr(65 + intdiv($i, 5)), chr(65 + $i % 5)),
        ]);
    }

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class, ['groupMode' => 'merchant', 'categorised' => 'uncategorised'])
        ->call('toggleCluster', 'AAA MERCHANT');

    expect($component->viewData('pageEligibleIds'))
        ->toEqualCanonicalizing($expanded->pluck('id')->all());

    // $expandedKey is a URL property and nothing clears it when the cluster
    // paginator moves, so the expansion outlives the page it was made on.
    $component->call('gotoPage', 2);

    expect($component->viewData('pageEligibleIds'))->toBe([])
        ->and($component->viewData('clusterRows')->count())->toBe(0)
        ->and($component->viewData('scopeCoversScreen'))->toBeFalse();

    // No checkboxes are rendered for those rows, so nothing may offer to
    // select them: the control would write to rows the user cannot see.
    $component->assertDontSee('data-testid="select-visible"', false);
});

it('ticks the rows a cluster selection covers so a later hand-pick drops only that row', function () {
    $rows = collect(range(1, 4))->map(fn (): Transaction => bulkTxn($this->user, $this->account));

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class, ['groupMode' => 'merchant', 'categorised' => 'uncategorised'])
        ->call('toggleCluster', 'NETFLIX.COM')
        ->call('selectCluster', 'NETFLIX.COM');

    expect(array_keys(array_filter($component->get('selected'))))
        ->toEqualCanonicalizing($rows->pluck('id')->all())
        ->and($component->viewData('selectionCount'))->toBe(4);

    // One checkbox unticked. Before the rows were materialised the client sent
    // the only key it knew about and the selection collapsed from four to one.
    $component->set('selected.'.$rows->first()->id, false);

    expect($component->viewData('selectionCount'))->toBe(3)
        ->and($component->get('bulkScope'))->toBeNull();
});

it('materialises nothing when the selected cluster is collapsed', function () {
    collect(range(1, 4))->map(fn (): Transaction => bulkTxn($this->user, $this->account));

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class, ['groupMode' => 'merchant', 'categorised' => 'uncategorised'])
        ->call('selectCluster', 'NETFLIX.COM');

    // Nothing is on screen to tick, and the scope still carries the write.
    expect($component->get('selected'))->toBe([])
        ->and($component->viewData('selectionCount'))->toBe(4)
        ->and($component->viewData('scopeCoversScreen'))->toBeFalse();
});
