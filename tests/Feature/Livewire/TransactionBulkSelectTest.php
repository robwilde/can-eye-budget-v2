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
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
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

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('selectAllMatching');

    // plain + other are eligible; the transfer is not.
    expect($component->viewData('selectionCount'))->toBe(2);

    $component->set('bulkCategoryId', (string) $this->category->id)
        ->call('applyCategoryToSelection');

    expect($plain->fresh()->category_id)->toBe($this->category->id)
        ->and($transfer->fresh()->category_id)->toBeNull();
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
    $a = bulkTxn($this->user, $this->account);
    $b = bulkTxn($this->user, $this->account);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class);

    $ids = $component->viewData('pageEligibleIds');
    expect($ids)->toHaveCount(2);

    $component->call('toggleVisible', $ids, true);
    expect($component->viewData('selectionCount'))->toBe(2);

    $component->call('toggleVisible', $ids, false);
    expect($component->viewData('selectionCount'))->toBe(0);

    expect([$a->id, $b->id])->toEqualCanonicalizing($ids);
});

it('keeps excluded rows out of the on-screen eligible set', function () {
    $other = bulkTxn($this->user, $this->account);
    bulkTxn($this->user, $this->account, ['transfer_pair_id' => $other->id]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class);

    // $other plus the transfer are on screen; only $other is eligible.
    expect($component->viewData('pageEligibleIds'))->toBe([$other->id]);
});
