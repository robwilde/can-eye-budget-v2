<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reports\CategoryGroupMap;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->food = CategoryGroup::factory()->for($this->user)->create(['name' => 'Food Group']);
    $this->savings = CategoryGroup::factory()->for($this->user)->create(['name' => 'Savings Group']);
});

function groupFilterRow(User $user, Account $account, ?Category $category, string $description, string $date = '2026-10-10'): Transaction
{
    return Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $category?->id,
        'description' => $description,
        'post_date' => $date,
    ]);
}

function groupFilterList(User $user, int $groupId): Testable
{
    return Livewire::actingAs($user)
        ->withQueryParams([
            'group' => $groupId,
            'direction' => 'outgoing',
            'period' => 'custom',
            'from' => '2026-10-01',
            'to' => '2026-10-31',
        ])
        ->test(TransactionList::class);
}

test('the group filter lists rows in the group root and its inheriting children but not an overriding child', function () {
    $root = Category::factory()->for($this->user)->create(['group_id' => $this->food->id]);
    $inheriting = Category::factory()->withParent($root)->create();
    $override = Category::factory()->withParent($root)->create(['group_id' => $this->savings->id]);
    $unrelated = Category::factory()->for($this->user)->create(['group_id' => $this->savings->id]);

    groupFilterRow($this->user, $this->account, $root, 'ROOT ROW');
    groupFilterRow($this->user, $this->account, $inheriting, 'INHERITING ROW');
    groupFilterRow($this->user, $this->account, $override, 'OVERRIDE ROW');
    groupFilterRow($this->user, $this->account, $unrelated, 'UNRELATED ROW');
    groupFilterRow($this->user, $this->account, null, 'UNCATEGORISED ROW');

    groupFilterList($this->user, $this->food->id)
        ->assertSee('ROOT ROW')
        ->assertSee('INHERITING ROW')
        ->assertDontSee('OVERRIDE ROW')
        ->assertDontSee('UNRELATED ROW')
        ->assertDontSee('UNCATEGORISED ROW');
});

test('the group filter keeps rows inside the requested dates and direction', function () {
    $root = Category::factory()->for($this->user)->create(['group_id' => $this->food->id]);

    groupFilterRow($this->user, $this->account, $root, 'IN RANGE');
    groupFilterRow($this->user, $this->account, $root, 'LAST MONTH', '2026-09-30');
    groupFilterRow($this->user, $this->account, $root, 'NEXT MONTH', '2026-11-01');
    Transaction::factory()->for($this->user)->credit()->create([
        'account_id' => $this->account->id,
        'category_id' => $root->id,
        'description' => 'INCOMING ROW',
        'post_date' => '2026-10-10',
    ]);

    groupFilterList($this->user, $this->food->id)
        ->assertSee('IN RANGE')
        ->assertDontSee('LAST MONTH')
        ->assertDontSee('NEXT MONTH')
        ->assertDontSee('INCOMING ROW');
});

test('a split row with a line in the group is listed and one without is not', function () {
    $foodRoot = Category::factory()->for($this->user)->create(['group_id' => $this->food->id]);
    $foodChild = Category::factory()->withParent($foodRoot)->create();
    $savingsRoot = Category::factory()->for($this->user)->create(['group_id' => $this->savings->id]);
    $plain = Category::factory()->for($this->user)->create();

    $mixed = groupFilterRow($this->user, $this->account, null, 'MIXED SPLIT');
    $mixed->splits()->createMany([
        ['category_id' => $foodChild->id, 'amount' => -6000, 'position' => 0],
        ['category_id' => $savingsRoot->id, 'amount' => -4000, 'position' => 1],
    ]);

    $elsewhere = groupFilterRow($this->user, $this->account, null, 'OTHER SPLIT');
    $elsewhere->splits()->createMany([
        ['category_id' => $savingsRoot->id, 'amount' => -6000, 'position' => 0],
        ['category_id' => $plain->id, 'amount' => -4000, 'position' => 1],
    ]);

    groupFilterList($this->user, $this->food->id)
        ->assertSee('MIXED SPLIT')
        ->assertDontSee('OTHER SPLIT');
});

test('the group filter never lists another users rows', function () {
    $root = Category::factory()->for($this->user)->create(['group_id' => $this->food->id]);
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();

    groupFilterRow($this->user, $this->account, $root, 'MY ROW');
    groupFilterRow($other, $otherAccount, $root, 'THEIR ROW');

    groupFilterList($this->user, $this->food->id)
        ->assertSee('MY ROW')
        ->assertDontSee('THEIR ROW');
});

test("another user's group id lists nothing and never shows their group name", function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();
    $theirGroup = CategoryGroup::factory()->for($other)->create(['name' => 'Their Group']);
    $theirRoot = Category::factory()->for($other)->create(['group_id' => $theirGroup->id]);
    $root = Category::factory()->for($this->user)->create(['group_id' => $this->food->id]);

    groupFilterRow($this->user, $this->account, $root, 'MY ROW');
    groupFilterRow($other, $otherAccount, $theirRoot, 'THEIR ROW');

    groupFilterList($this->user, $theirGroup->id)
        ->assertDontSee('MY ROW')
        ->assertDontSee('THEIR ROW')
        ->assertDontSee('Their Group');
});

test('an unknown group id lists nothing rather than everything', function () {
    $root = Category::factory()->for($this->user)->create(['group_id' => $this->food->id]);
    groupFilterRow($this->user, $this->account, $root, 'FOOD ROW');
    groupFilterRow($this->user, $this->account, null, 'LOOSE ROW');

    groupFilterList($this->user, 999999)
        ->assertDontSee('FOOD ROW')
        ->assertDontSee('LOOSE ROW');
});

test('an active group filter shows its name in the heading and a chip', function () {
    groupFilterList($this->user, $this->food->id)
        ->assertSee('Food Group Transactions')
        ->assertSeeHtml('data-testid="group-filter-chip"')
        ->assertSeeHtml('aria-label="Clear group filter"');
});

test('clearing the chip removes the group filter and restores every row', function () {
    $root = Category::factory()->for($this->user)->create(['group_id' => $this->food->id]);
    groupFilterRow($this->user, $this->account, $root, 'FOOD ROW');
    groupFilterRow($this->user, $this->account, null, 'LOOSE ROW');

    groupFilterList($this->user, $this->food->id)
        ->assertDontSee('LOOSE ROW')
        ->call('$set', 'group', null)
        ->assertSet('group', null)
        ->assertSee('FOOD ROW')
        ->assertSee('LOOSE ROW')
        ->assertSee('All Transactions')
        ->assertDontSeeHtml('data-testid="group-filter-chip"');
});

test('changing the group filter returns to the first page', function () {
    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('group', $this->food->id)
        ->assertSet('paginators.page', 1);
});

test('categoryIdsInGroup returns ascending ids resolving to the group through their anchor', function () {
    $root = Category::factory()->for($this->user)->create(['group_id' => $this->food->id]);
    $child = Category::factory()->withParent($root)->create();
    $grandchild = Category::factory()->withParent($child)->create();
    $override = Category::factory()->withParent($root)->create(['group_id' => $this->savings->id]);
    $overrideChild = Category::factory()->withParent($override)->create();

    $map = CategoryGroupMap::load($this->user->id);

    expect($map->categoryIdsInGroup($this->food->id))->toBe([$root->id, $child->id, $grandchild->id])
        ->and($map->categoryIdsInGroup($this->savings->id))->toBe([$override->id, $overrideChild->id])
        ->and($map->categoryIdsInGroup(999999))->toBe([]);
});
