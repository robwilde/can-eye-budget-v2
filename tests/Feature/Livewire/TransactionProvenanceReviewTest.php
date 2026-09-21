<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\TransactionDirection;
use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\Category;
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
