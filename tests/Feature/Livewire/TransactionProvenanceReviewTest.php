<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\TransactionDirection;
use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->category = Category::factory()->create(['is_hidden' => false]);
});

function provenanceRow(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'NETFLIX.COM',
        'merchant_name' => null,
        'clean_description' => null,
        'amount' => 1899,
        'direction' => TransactionDirection::Debit,
        'post_date' => CarbonImmutable::parse('2026-06-10'),
        'transfer_pair_id' => null,
    ], $overrides));
}

it('filters the list down to machine-set categories', function () {
    $byRule = provenanceRow($this->user, $this->account, [
        'category_id' => $this->category->id,
        'category_source' => CategorySource::Rule,
    ]);

    provenanceRow($this->user, $this->account, [
        'category_id' => $this->category->id,
        'category_source' => CategorySource::Manual,
    ]);

    $ids = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('source', 'rule')
        ->viewData('transactions')
        ->getCollection()
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$byRule->id]);
});

it('filters the list down to categories the user set', function () {
    provenanceRow($this->user, $this->account, [
        'category_id' => $this->category->id,
        'category_source' => CategorySource::Rule,
    ]);

    $manual = provenanceRow($this->user, $this->account, [
        'category_id' => $this->category->id,
        'category_source' => CategorySource::Manual,
    ]);

    $ids = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('source', 'manual')
        ->viewData('transactions')
        ->getCollection()
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$manual->id]);
});

it('reverts rule-set categories in one action and leaves manual ones alone', function () {
    $byRule = provenanceRow($this->user, $this->account, [
        'category_id' => $this->category->id,
        'category_source' => CategorySource::Rule,
    ]);

    $manual = provenanceRow($this->user, $this->account, [
        'category_id' => $this->category->id,
        'category_source' => CategorySource::Manual,
    ]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$byRule->id => true, $manual->id => true])
        ->call('revertMachineCategories');

    expect($byRule->fresh()->category_id)->toBeNull()
        ->and($byRule->fresh()->category_source)->toBeNull()
        ->and($manual->fresh()->category_id)->toBe($this->category->id)
        ->and($manual->fresh()->category_source)->toBe(CategorySource::Manual);
});

it('reports how many categories were reverted', function () {
    $a = provenanceRow($this->user, $this->account, [
        'category_id' => $this->category->id,
        'category_source' => CategorySource::Rule,
    ]);
    $b = provenanceRow($this->user, $this->account, [
        'category_id' => $this->category->id,
        'category_source' => CategorySource::Rule,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$a->id => true, $b->id => true])
        ->call('revertMachineCategories');

    expect($component->get('bulkNotice'))->toBe('Reverted 2 rule-set categories.');
});

it('reverts a whole filter-wide scope of machine-set rows', function () {
    foreach (range(1, 4) as $ignored) {
        provenanceRow($this->user, $this->account, [
            'category_id' => $this->category->id,
            'category_source' => CategorySource::Rule,
        ]);
    }

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('source', 'rule')
        ->call('selectAllMatching')
        ->call('revertMachineCategories');

    expect(Transaction::where('user_id', $this->user->id)->whereNotNull('category_id')->count())->toBe(0);
});

it('never reverts another users rows', function () {
    $intruder = User::factory()->create();
    $intruderAccount = Account::factory()->for($intruder)->create();

    $theirs = provenanceRow($intruder, $intruderAccount, [
        'category_id' => $this->category->id,
        'category_source' => CategorySource::Rule,
    ]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('selected', [$theirs->id => true])
        ->call('revertMachineCategories');

    expect($theirs->fresh()->category_id)->toBe($this->category->id);
});

it('falls back to any-source for an unknown source filter', function () {
    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class, ['source' => 'nonsense']);

    expect($component->get('source'))->toBe('all');
});

it('leaves unselected planned siblings and the plan alone when reverting', function () {
    $manualCategory = Category::factory()->create(['is_hidden' => false]);
    $planned = PlannedTransaction::factory()->for($this->user)->for($this->account)->create([
        'category_id' => $manualCategory->id,
    ]);

    $byRule = provenanceRow($this->user, $this->account, [
        'planned_transaction_id' => $planned->id,
        'category_id' => $this->category->id,
        'category_source' => CategorySource::Rule,
    ]);
    $manualSibling = provenanceRow($this->user, $this->account, [
        'planned_transaction_id' => $planned->id,
        'category_id' => $manualCategory->id,
        'category_source' => CategorySource::Manual,
    ]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('source', 'rule')
        ->set('selected', [$byRule->id => true])
        ->call('revertMachineCategories');

    expect($byRule->fresh()->category_id)->toBeNull()
        ->and($manualSibling->fresh()->category_id)->toBe($manualCategory->id)
        ->and($manualSibling->fresh()->category_source)->toBe(CategorySource::Manual)
        ->and($planned->fresh()->category_id)->toBe($manualCategory->id);
});

it('returns to the first page after reverting the last page of the rule view', function () {
    $rows = collect(range(1, 30))->map(fn (): Transaction => provenanceRow($this->user, $this->account, [
        'category_id' => $this->category->id,
        'category_source' => CategorySource::Rule,
    ]));

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('source', 'rule')
        ->call('gotoPage', 2)
        ->set('selected', $rows->slice(25)->mapWithKeys(fn (Transaction $t): array => [$t->id => true])->all())
        ->call('revertMachineCategories');

    $transactions = $component->viewData('transactions');

    expect($transactions->currentPage())->toBe(1)
        ->and($transactions->total())->toBe(25);
});

it('drops the source filter when switching to uncategorised', function () {
    $uncategorised = provenanceRow($this->user, $this->account, ['category_id' => null]);

    $component = Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('source', 'rule')
        ->set('categorised', 'uncategorised')
        ->assertSet('source', 'all');

    expect($component->viewData('transactions')->pluck('id')->all())->toBe([$uncategorised->id]);
});

it('ignores a source filter on a direct uncategorised load', function () {
    Livewire::actingAs($this->user)
        ->test(TransactionList::class, ['categorised' => 'uncategorised', 'source' => 'rule'])
        ->assertSet('source', 'all');
});

it('refuses a source filter while uncategorised is selected', function () {
    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->set('categorised', 'uncategorised')
        ->set('source', 'manual')
        ->assertSet('source', 'all');
});
