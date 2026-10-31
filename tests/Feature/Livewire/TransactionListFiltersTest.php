<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function filterRow(User $user, Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'ROW '.fake()->unique()->numerify('######'),
        'amount' => 1000,
        'direction' => TransactionDirection::Debit,
        'status' => TransactionStatus::Posted,
        'post_date' => '2026-10-10',
        'category_id' => null,
        'transfer_pair_id' => null,
    ], $overrides));
}

function pairLegs(Transaction $debit, Transaction $credit): void
{
    $debit->update(['transfer_pair_id' => $credit->id]);
    $credit->update(['transfer_pair_id' => $debit->id]);
}

function filteredList(User $user, array $params = []): Testable
{
    return Livewire::actingAs($user)
        ->withQueryParams(array_merge(['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'], $params))
        ->test(TransactionList::class);
}

function listedIds(Testable $component): array
{
    return collect($component->viewData('transactions')->items())->pluck('id')->sort()->values()->all();
}

it('includes subcategories when filtering by a parent category', function () {
    $food = Category::factory()->for($this->user)->create(['is_hidden' => false]);
    $groceries = Category::factory()->withParent($food)->create();
    $other = Category::factory()->for($this->user)->create();
    $inFood = filterRow($this->user, $this->account, ['category_id' => $food->id]);
    $inGroceries = filterRow($this->user, $this->account, ['category_id' => $groceries->id]);
    filterRow($this->user, $this->account, ['category_id' => $other->id]);

    $component = filteredList($this->user, ['category' => $food->id]);

    expect(listedIds($component))->toBe(collect([$inFood->id, $inGroceries->id])->sort()->values()->all());
});

it('does not widen a child category filter to its parent', function () {
    $food = Category::factory()->for($this->user)->create();
    $groceries = Category::factory()->withParent($food)->create();
    filterRow($this->user, $this->account, ['category_id' => $food->id]);
    $inGroceries = filterRow($this->user, $this->account, ['category_id' => $groceries->id]);

    expect(listedIds(filteredList($this->user, ['category' => $groceries->id])))->toBe([$inGroceries->id]);
});

it('filters transactions by amount greater than a value', function () {
    filterRow($this->user, $this->account, ['amount' => 1000]);
    $five = filterRow($this->user, $this->account, ['amount' => 5000]);
    $nine = filterRow($this->user, $this->account, ['amount' => 9000]);

    $component = filteredList($this->user, ['amountOp' => 'gt', 'amountCents' => 4000]);

    expect(listedIds($component))->toBe(collect([$five->id, $nine->id])->sort()->values()->all());
});

it('filters by amount strictly less than a value and by amount equal to it', function () {
    $one = filterRow($this->user, $this->account, ['amount' => 1000]);
    $five = filterRow($this->user, $this->account, ['amount' => 5000]);
    filterRow($this->user, $this->account, ['amount' => 9000]);

    expect(listedIds(filteredList($this->user, ['amountOp' => 'lt', 'amountCents' => 5000])))->toBe([$one->id])
        ->and(listedIds(filteredList($this->user, ['amountOp' => 'eq', 'amountCents' => 5000])))->toBe([$five->id]);
});

it('compares the absolute amount whatever the stored sign', function () {
    $negative = filterRow($this->user, $this->account, ['amount' => 5000]);
    Transaction::query()->whereKey($negative->id)->toBase()->update(['amount' => -5000]);
    filterRow($this->user, $this->account, ['amount' => 1000]);

    expect(listedIds(filteredList($this->user, ['amountOp' => 'gt', 'amountCents' => 4000])))->toBe([$negative->id]);
});

it('converts the dollar amount typed by the user to cents', function () {
    filterRow($this->user, $this->account, ['amount' => 1000]);
    $five = filterRow($this->user, $this->account, ['amount' => 5000]);

    $component = filteredList($this->user)
        ->set('amountOp', 'gt')
        ->set('amountDollars', '40.00');

    expect($component->get('amountCents'))->toBe(4000)
        ->and(listedIds($component))->toBe([$five->id]);
});

it('ignores an amount operator with no amount', function () {
    filterRow($this->user, $this->account);
    filterRow($this->user, $this->account);

    $component = filteredList($this->user, ['amountOp' => 'gt']);

    expect($component->viewData('transactions')->total())->toBe(2);
});

it('filters by a single status', function () {
    $posted = filterRow($this->user, $this->account, ['status' => TransactionStatus::Posted]);
    $pending = filterRow($this->user, $this->account, ['status' => TransactionStatus::Pending]);

    expect(listedIds(filteredList($this->user, ['statuses' => ['pending']])))->toBe([$pending->id])
        ->and(listedIds(filteredList($this->user, ['statuses' => ['posted']])))->toBe([$posted->id]);
});

it('applies no status filter when both statuses are selected', function () {
    filterRow($this->user, $this->account, ['status' => TransactionStatus::Posted]);
    filterRow($this->user, $this->account, ['status' => TransactionStatus::Pending]);

    expect(filteredList($this->user, ['statuses' => ['posted', 'pending']])->viewData('transactions')->total())->toBe(2)
        ->and(filteredList($this->user)->viewData('transactions')->total())->toBe(2);
});

it('drops unknown statuses from the query string', function () {
    filterRow($this->user, $this->account);

    $component = filteredList($this->user, ['statuses' => ['bogus']]);

    expect($component->get('statuses'))->toBe([])
        ->and($component->viewData('transactions')->total())->toBe(1);
});

it('clears the amount and status chips one at a time', function () {
    filterRow($this->user, $this->account);

    filteredList($this->user, ['amountOp' => 'gt', 'amountCents' => 4000, 'statuses' => ['pending']])
        ->assertSeeHtml('data-testid="amount-filter-chip"')
        ->assertSeeHtml('data-testid="status-filter-chip"')
        ->call('clearAmountFilter')
        ->assertSet('amountOp', 'any')
        ->assertSet('amountCents', null)
        ->assertSet('statuses', ['pending'])
        ->assertDontSeeHtml('data-testid="amount-filter-chip"')
        ->call('clearStatus', 'pending')
        ->assertSet('statuses', [])
        ->assertDontSeeHtml('data-testid="status-filter-chip"');
});

it('totals a mixed set with the transfer counting rule', function () {
    $tracked = Account::factory()->for($this->user)->create();
    $untracked = Account::factory()->for($this->user)->untracked()->create();

    $toUntrackedOut = filterRow($this->user, $this->account, ['amount' => 7000]);
    $toUntrackedIn = filterRow($this->user, $untracked, ['amount' => 7000, 'direction' => TransactionDirection::Credit]);
    pairLegs($toUntrackedOut, $toUntrackedIn);

    $betweenOut = filterRow($this->user, $this->account, ['amount' => 3000]);
    $betweenIn = filterRow($this->user, $tracked, ['amount' => 3000, 'direction' => TransactionDirection::Credit]);
    pairLegs($betweenOut, $betweenIn);

    filterRow($this->user, $this->account, ['amount' => 1500]);
    filterRow($this->user, $this->account, ['amount' => 20000, 'direction' => TransactionDirection::Credit]);

    expect(filteredList($this->user)->viewData('totals'))->toMatchArray([
        'count' => 5,
        'transfersOnly' => false,
        'income' => 20000,
        'expenses' => 7000 + 1500,
        'inflow' => 0,
        'outflow' => 0,
    ]);
});

it('shows inflow and outflow beside the countable totals when the filtered set holds only transfers', function () {
    $tracked = Account::factory()->for($this->user)->create();
    $untracked = Account::factory()->for($this->user)->untracked()->create();

    $toUntrackedOut = filterRow($this->user, $this->account, ['amount' => 7000]);
    $toUntrackedIn = filterRow($this->user, $untracked, ['amount' => 7000, 'direction' => TransactionDirection::Credit]);
    pairLegs($toUntrackedOut, $toUntrackedIn);

    $betweenOut = filterRow($this->user, $this->account, ['amount' => 3000]);
    $betweenIn = filterRow($this->user, $tracked, ['amount' => 3000, 'direction' => TransactionDirection::Credit]);
    pairLegs($betweenOut, $betweenIn);

    $component = filteredList($this->user);

    expect($component->viewData('totals'))->toMatchArray([
        'count' => 3,
        'transfersOnly' => true,
        'income' => 0,
        'expenses' => 7000,
        'inflow' => 3000,
        'outflow' => 10000,
    ]);
    $component
        ->assertSeeHtml('data-testid="totals-income"')
        ->assertSeeHtml('data-testid="totals-expenses"')
        ->assertSeeHtml('data-testid="totals-inflow"')
        ->assertSeeHtml('data-testid="totals-outflow"');
});

it('hides inflow and outflow when the filtered set holds more than transfers', function () {
    filterRow($this->user, $this->account);

    filteredList($this->user)
        ->assertSeeHtml('data-testid="totals-expenses"')
        ->assertDontSeeHtml('data-testid="totals-inflow"');
});

it('totals the whole filtered set rather than one page', function () {
    foreach (range(1, 30) as $i) {
        filterRow($this->user, $this->account, ['amount' => 100]);
    }

    $component = filteredList($this->user);

    expect($component->viewData('totals'))->toMatchArray(['count' => 30, 'expenses' => 3000]);
});

it('totals only the rows merchant mode can show', function () {
    filterRow($this->user, $this->account, ['amount' => 2000, 'description' => 'NETFLIX.COM']);
    $unclusterable = filterRow($this->user, $this->account, ['amount' => 9000, 'description' => 'MYSTERY']);
    Transaction::query()->whereKey($unclusterable->id)->update(['merchant_key' => null]);

    $merchantMode = filteredList($this->user, ['categorised' => 'uncategorised', 'groupMode' => 'merchant']);
    $dateMode = filteredList($this->user, ['categorised' => 'uncategorised']);

    expect($merchantMode->viewData('totals'))->toMatchArray(['count' => 1, 'expenses' => 2000])
        ->and($dateMode->viewData('totals'))->toMatchArray(['count' => 2, 'expenses' => 11000]);
});

it('totals only the users own transactions', function () {
    $stranger = User::factory()->create();
    $strangerAccount = Account::factory()->for($stranger)->create();
    $mine = filterRow($this->user, $this->account, ['amount' => 5000]);
    filterRow($stranger, $strangerAccount, ['amount' => 9000]);
    filterRow($stranger, $strangerAccount, ['amount' => 9000, 'direction' => TransactionDirection::Credit]);

    $component = filteredList($this->user, ['amountOp' => 'gt', 'amountCents' => 4000]);

    expect(listedIds($component))->toBe([$mine->id])
        ->and($component->viewData('totals'))->toMatchArray(['count' => 1, 'income' => 0, 'expenses' => 5000]);
});

it('carries the amount, status and category filters into a bulk select-all scope', function () {
    $food = Category::factory()->for($this->user)->create(['is_hidden' => false]);
    $groceries = Category::factory()->withParent($food)->create();
    $target = Category::factory()->for($this->user)->create(['is_hidden' => false]);
    $unrelated = Category::factory()->for($this->user)->create(['is_hidden' => false]);

    $match = filterRow($this->user, $this->account, ['amount' => 5000, 'category_id' => $groceries->id]);
    $tooSmall = filterRow($this->user, $this->account, ['amount' => 1000, 'category_id' => $groceries->id]);
    $pending = filterRow($this->user, $this->account, ['amount' => 5000, 'category_id' => $groceries->id, 'status' => TransactionStatus::Pending]);
    $otherCategory = filterRow($this->user, $this->account, ['amount' => 5000, 'category_id' => $unrelated->id]);

    $component = filteredList($this->user, [
        'category' => $food->id,
        'amountOp' => 'gt',
        'amountCents' => 4000,
        'statuses' => ['posted'],
    ])->call('selectAllMatching');

    expect($component->viewData('selectionCount'))->toBe(1);

    $component->set('bulkCategoryId', (string) $target->id)->call('applyCategoryToSelection');

    expect($match->fresh()->category_id)->toBe($target->id)
        ->and($tooSmall->fresh()->category_id)->toBe($groceries->id)
        ->and($pending->fresh()->category_id)->toBe($groceries->id)
        ->and($otherCategory->fresh()->category_id)->toBe($unrelated->id);
});

it('rebuilds the typed dollar amount from the amount in the query string', function () {
    filteredList($this->user, ['amountOp' => 'gt', 'amountCents' => 4000])
        ->assertSet('amountDollars', '40.00');
});

it('keeps the amount when the operator changes and clears it when the operator is any', function () {
    filteredList($this->user)
        ->set('amountOp', 'gt')
        ->set('amountDollars', '40')
        ->set('amountOp', 'lt')
        ->assertSet('amountCents', 4000)
        ->set('amountOp', 'any')
        ->assertSet('amountCents', null)
        ->assertSet('amountDollars', '');
});

it('reads a typed negative amount as its absolute value', function () {
    filteredList($this->user)
        ->set('amountOp', 'gt')
        ->set('amountDollars', '-40')
        ->assertSet('amountCents', 4000);
});

it('drops a typed amount that is not a number or is too large to store', function () {
    filteredList($this->user)
        ->set('amountOp', 'gt')
        ->set('amountDollars', '40')
        ->set('amountDollars', 'abc')
        ->assertSet('amountCents', null)
        ->set('amountDollars', '40')
        ->set('amountDollars', '1e20')
        ->assertSet('amountCents', null);
});

it('survives the most negative integer as the amount in the query string', function () {
    filterRow($this->user, $this->account);

    $component = filteredList($this->user, ['amountOp' => 'gt', 'amountCents' => PHP_INT_MIN]);

    expect($component->get('amountCents'))->toBeNull()
        ->and($component->viewData('transactions')->total())->toBe(1);
});

it('survives a status list holding non-string entries', function () {
    filterRow($this->user, $this->account);

    $component = filteredList($this->user, ['statuses' => [['pending'], 'posted']]);

    expect($component->get('statuses'))->toBe(['posted']);
});

it('totals a split transaction by the split lines inside the filtered category', function () {
    $groceries = Category::factory()->for($this->user)->create();
    $fuel = Category::factory()->for($this->user)->create();
    $split = filterRow($this->user, $this->account, ['amount' => 10000]);
    TransactionSplit::factory()->create(['transaction_id' => $split->id, 'category_id' => $groceries->id, 'amount' => 2000]);
    TransactionSplit::factory()->create(['transaction_id' => $split->id, 'category_id' => $fuel->id, 'amount' => 8000]);
    filterRow($this->user, $this->account, ['amount' => 500, 'category_id' => $groceries->id]);

    expect(filteredList($this->user, ['category' => $groceries->id])->viewData('totals'))
        ->toMatchArray(['count' => 2, 'expenses' => 2500])
        ->and(filteredList($this->user, ['category' => $fuel->id])->viewData('totals'))
        ->toMatchArray(['count' => 1, 'expenses' => 8000]);
});

it('totals the whole amount of a split transaction when no category is filtered', function () {
    $groceries = Category::factory()->for($this->user)->create();
    $split = filterRow($this->user, $this->account, ['amount' => 10000]);
    TransactionSplit::factory()->create(['transaction_id' => $split->id, 'category_id' => $groceries->id, 'amount' => 10000]);

    expect(filteredList($this->user)->viewData('totals'))->toMatchArray(['count' => 1, 'expenses' => 10000]);
});

it('limits the list to the exact category when the exact flag is set', function () {
    $food = Category::factory()->for($this->user)->create();
    $groceries = Category::factory()->withParent($food)->create();
    $inFood = filterRow($this->user, $this->account, ['category_id' => $food->id, 'amount' => 1000]);
    $inGroceries = filterRow($this->user, $this->account, ['category_id' => $groceries->id, 'amount' => 2000]);

    $exact = filteredList($this->user, ['category' => $food->id, 'exact' => 1]);

    expect(listedIds($exact))->toBe([$inFood->id])
        ->and($exact->viewData('totals'))->toMatchArray(['count' => 1, 'expenses' => 1000])
        ->and(listedIds(filteredList($this->user, ['category' => $food->id])))
        ->toBe(collect([$inFood->id, $inGroceries->id])->sort()->values()->all());
});

it('widens to subcategories again once the user picks a category', function () {
    $food = Category::factory()->for($this->user)->create();
    $groceries = Category::factory()->withParent($food)->create();
    $inGroceries = filterRow($this->user, $this->account, ['category_id' => $groceries->id]);

    $component = filteredList($this->user, ['category' => $groceries->id, 'exact' => 1])
        ->set('category', $food->id);

    expect($component->get('exactCategory'))->toBeFalse()
        ->and(listedIds($component))->toBe([$inGroceries->id]);
});

it('applies a category and a group filter to the same split line', function () {
    $mixed = CategoryGroup::factory()->for($this->user)->create();
    $fuelOnly = CategoryGroup::factory()->for($this->user)->create();
    $groceries = Category::factory()->for($this->user)->create(['group_id' => $mixed->id]);
    $fuel = Category::factory()->for($this->user)->create(['group_id' => $fuelOnly->id]);
    $split = filterRow($this->user, $this->account, ['amount' => 10000]);
    TransactionSplit::factory()->create(['transaction_id' => $split->id, 'category_id' => $groceries->id, 'amount' => 2000]);
    TransactionSplit::factory()->create(['transaction_id' => $split->id, 'category_id' => $fuel->id, 'amount' => 8000]);

    expect(filteredList($this->user, ['category' => $groceries->id, 'group' => $mixed->id])->viewData('totals'))
        ->toMatchArray(['count' => 1, 'expenses' => 2000])
        ->and(filteredList($this->user, ['category' => $groceries->id, 'group' => $fuelOnly->id])->viewData('totals'))
        ->toMatchArray(['count' => 0, 'expenses' => 0]);
});
