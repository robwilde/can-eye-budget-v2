<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Casts\MoneyCast;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\CategoryReport;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-15'));
});

test('guests are redirected from the reports page', function () {
    $this->get(route('reports'))->assertRedirect(route('login'));
});

test('authenticated user sees the reports page with both directions and mode tabs', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertOk()
        ->assertSee('data-testid="category-report"', false)
        ->assertSee('Real')
        ->assertSee('Plan')
        ->assertSee('Expenses')
        ->assertSee('Incomes');
});

test('rolls a sub-category transaction up to its root and hides detail until expanded', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $root = Category::factory()->create(['name' => 'Retail Trade']);
    $child = Category::factory()->withParent($root)->create(['name' => 'Food Retailing']);
    $grandchild = Category::factory()->withParent($child)->create(['name' => 'Supermarkets']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $grandchild->id,
        'amount' => 4200,
        'post_date' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->assertSee('Retail Trade')
        ->assertSee(MoneyCast::format(4200))
        ->assertDontSee('Supermarkets');
});

test('sums the absolute amount regardless of the stored sign', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $groceries = Category::factory()->create(['name' => 'Groceries']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'amount' => 5000,
        'post_date' => now(),
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'amount' => -3000,
        'post_date' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->assertSee('Groceries')
        ->assertSee(MoneyCast::format(8000));
});

test('expenses and incomes are shown at the same time', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $spend = Category::factory()->create(['name' => 'Dining']);
    $earn = Category::factory()->create(['name' => 'Salary']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $spend->id,
        'amount' => 3000,
        'post_date' => now(),
    ]);
    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'category_id' => $earn->id,
        'amount' => 900000,
        'post_date' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->assertSee('Dining')
        ->assertSee('Salary');
});

test('expanding a root reveals its full-path descendants inline', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $root = Category::factory()->create(['name' => 'Retail Trade']);
    $child = Category::factory()->withParent($root)->create(['name' => 'Food Retailing']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $child->id,
        'amount' => 6000,
        'post_date' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->assertDontSee('Retail Trade / Food Retailing')
        ->call('toggleExpand', $root->id)
        ->assertSee('Retail Trade / Food Retailing')
        ->call('toggleExpand', $root->id)
        ->assertDontSee('Retail Trade / Food Retailing');
});

test('the subcategories toggle expands every root at once', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $root = Category::factory()->create(['name' => 'Retail Trade']);
    $child = Category::factory()->withParent($root)->create(['name' => 'Food Retailing']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $child->id,
        'amount' => 6000,
        'post_date' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->assertDontSee('Retail Trade / Food Retailing')
        ->set('showSubcategories', true)
        ->assertSee('Retail Trade / Food Retailing');
});

test('the top toggle limits the list to the five largest categories', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $amounts = [
        'Alpha' => 6000,
        'Bravo' => 5000,
        'Charlie' => 4000,
        'Delta' => 3000,
        'Echo' => 2000,
        'Foxtrot' => 1000,
    ];

    foreach ($amounts as $name => $amount) {
        $category = Category::factory()->create(['name' => $name]);
        Transaction::factory()->for($user)->debit()->create([
            'account_id' => $account->id,
            'category_id' => $category->id,
            'amount' => $amount,
            'post_date' => now(),
        ]);
    }

    $component = Livewire::actingAs($user)->test(CategoryReport::class);

    expect(collect($component->instance()->expenseRows())->pluck('name'))->toContain('Foxtrot');

    $rows = collect($component->set('topOnly', true)->instance()->expenseRows());

    expect($rows)->toHaveCount(5)
        ->and($rows->pluck('name')->all())->toBe(['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo']);
});

test('plan mode reports planned amounts that are absent from real mode', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $rent = Category::factory()->create(['name' => 'Rent']);

    PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'category_id' => $rent->id,
        'amount' => 200000,
        'direction' => TransactionDirection::Debit,
        'start_date' => now()->startOfMonth(),
        'frequency' => RecurrenceFrequency::EveryMonth,
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->assertDontSee('Rent')
        ->set('mode', 'plan')
        ->assertSee('Rent')
        ->assertSee(MoneyCast::format(200000));
});

test('period filter includes only transactions inside the selected range', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $recent = Category::factory()->create(['name' => 'Dining']);
    $old = Category::factory()->create(['name' => 'Transport']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $recent->id,
        'amount' => 1000,
        'post_date' => now(),
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $old->id,
        'amount' => 2000,
        'post_date' => now()->subMonth(),
    ]);

    Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->assertSee('Dining')
        ->assertDontSee('Transport')
        ->set('period', 'custom')
        ->set('from', '2026-06-01')
        ->set('to', '2026-06-30')
        ->assertSee('Transport')
        ->assertDontSee('Dining');
});

test('transactions in a transfer pair never appear in buckets or summary', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $groceries = Category::factory()->create(['name' => 'Groceries']);
    $moved = Category::factory()->create(['name' => 'MovedMoney']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'amount' => 3000,
        'post_date' => now(),
    ]);

    $outgoing = Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $moved->id,
        'amount' => 99999,
        'post_date' => now(),
    ]);
    $incoming = Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 99999,
        'post_date' => now(),
    ]);
    $outgoing->update(['transfer_pair_id' => $incoming->id]);
    $incoming->update(['transfer_pair_id' => $outgoing->id]);

    Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->assertSee('Groceries')
        ->assertDontSee('MovedMoney')
        ->assertDontSee(MoneyCast::format(99999));
});

test('superseded transaction versions are counted once at the child amount', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $groceries = Category::factory()->create(['name' => 'Groceries']);

    $parent = Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'amount' => 5000,
        'post_date' => now(),
    ]);
    $parent->createChild(['amount' => 4200]);

    Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->assertSee('Groceries')
        ->assertSee(MoneyCast::format(4200))
        ->assertDontSee(MoneyCast::format(9200));
});

test('other users transactions never appear', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();
    $mine = Category::factory()->create(['name' => 'MyGroceries']);
    $theirs = Category::factory()->create(['name' => 'TheirGroceries']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $mine->id,
        'amount' => 3000,
        'post_date' => now(),
    ]);
    Transaction::factory()->for($other)->debit()->create([
        'account_id' => $otherAccount->id,
        'category_id' => $theirs->id,
        'amount' => 7000,
        'post_date' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->assertSee('MyGroceries')
        ->assertDontSee('TheirGroceries');
});

test('null-category transactions produce an uncategorised bucket', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => null,
        'amount' => 4000,
        'post_date' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->assertSee('Uncategorised')
        ->assertSee(MoneyCast::format(4000));
});

test('summary reports in, out, net with per-month and percentage-of-income figures', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 500000,
        'post_date' => now(),
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => 200000,
        'post_date' => now(),
    ]);

    $summary = Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->instance()
        ->summary();

    expect($summary['in'])->toBe(500000)
        ->and($summary['out'])->toBe(200000)
        ->and($summary['net'])->toBe(300000)
        ->and($summary['months'])->toBe(1)
        ->and($summary['inPerMonth'])->toBe(500000)
        ->and($summary['outPctIncome'])->toBe(40)
        ->and($summary['netPctIncome'])->toBe(60);
});

test('each bucket carries its share of the direction total', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $big = Category::factory()->create(['name' => 'Housing']);
    $small = Category::factory()->create(['name' => 'Fun']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $big->id,
        'amount' => 7500,
        'post_date' => now(),
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $small->id,
        'amount' => 2500,
        'post_date' => now(),
    ]);

    $buckets = collect(
        Livewire::actingAs($user)
            ->test(CategoryReport::class)
            ->instance()
            ->expenses()['buckets']
    );

    expect($buckets->firstWhere('name', 'Housing')['pct'])->toBe(75.0)
        ->and($buckets->firstWhere('name', 'Fun')['pct'])->toBe(25.0);
});

test('the monthly chart series covers each month in the range', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => 3000,
        'post_date' => '2026-05-10',
    ]);
    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 9000,
        'post_date' => '2026-07-02',
    ]);

    $chart = Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->set('period', 'custom')
        ->set('from', '2026-05-01')
        ->set('to', '2026-07-31')
        ->instance()
        ->chart();

    expect($chart['labels'])->toBe(['May 26', 'Jun 26', 'Jul 26'])
        ->and($chart['expense'])->toBe([3000, 0, 0])
        ->and($chart['income'])->toBe([0, 0, 9000])
        ->and($chart['net'])->toBe([-3000, 0, 9000]);
});

test('treemap nodes are produced per direction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $spend = Category::factory()->create(['name' => 'Dining']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $spend->id,
        'amount' => 3000,
        'post_date' => now(),
    ]);

    $treemap = Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->instance()
        ->treemap();

    expect($treemap['expense'])->toHaveCount(1)
        ->and($treemap['expense'][0]['x'])->toBe('Dining')
        ->and($treemap['expense'][0]['y'])->toBe(3000)
        ->and($treemap['income'])->toBe([]);
});

test('an invalid period or mode falls back to the defaults', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CategoryReport::class, ['period' => 'nonsense', 'mode' => 'bogus'])
        ->assertSet('period', 'this-month')
        ->assertSet('mode', 'real')
        ->set('mode', 'also-bad')
        ->assertSet('mode', 'real');
});

test('custom date bounds are normalised to canonical dates or cleared', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->set('period', 'custom')
        ->set('from', 'not-a-date')
        ->assertSet('from', null)
        ->set('to', '2026-07-15')
        ->assertSet('to', '2026-07-15');
});

test('a cyclic category chain collapses into the uncategorised bucket', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $a = Category::factory()->create(['name' => 'LoopA']);
    $b = Category::factory()->withParent($a)->create(['name' => 'LoopB']);
    $a->update(['parent_id' => $b->id]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $b->id,
        'amount' => 4000,
        'post_date' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->assertSee('Uncategorised')
        ->assertSee(MoneyCast::format(4000))
        ->assertDontSee('LoopB');
});

test('a chain deeper than ten levels still rolls up to the true root', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $root = Category::factory()->create(['name' => 'DeepRoot']);
    $current = $root;

    for ($level = 1; $level <= 11; $level++) {
        $current = Category::factory()->withParent($current)->create(['name' => "Level{$level}"]);
    }

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $current->id,
        'amount' => 5000,
        'post_date' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->assertSee('DeepRoot')
        ->assertSee(MoneyCast::format(5000));
});

test('category colours are whitelisted to safe hex before reaching inline styles', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $safe = Category::factory()->withColor('#AABBCC')->create(['name' => 'SafeColour']);
    $evil = Category::factory()->withColor('red;background-image:url(//x)')->create(['name' => 'EvilColour']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $safe->id,
        'amount' => 5000,
        'post_date' => now(),
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $evil->id,
        'amount' => 3000,
        'post_date' => now(),
    ]);

    $buckets = collect(
        Livewire::actingAs($user)
            ->test(CategoryReport::class)
            ->instance()
            ->expenses()['buckets']
    );

    expect($buckets->firstWhere('name', 'SafeColour')['color'])->toBe('#AABBCC')
        ->and($buckets->firstWhere('name', 'EvilColour')['color'])->toMatch('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/')
        ->and($buckets->firstWhere('name', 'EvilColour')['color'])->not->toContain('url');
});

test('inline children exclude the root category own direct spend', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $root = Category::factory()->create(['name' => 'Office']);
    $child = Category::factory()->withParent($root)->create(['name' => 'Software']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $root->id,
        'amount' => 2000,
        'post_date' => now(),
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $child->id,
        'amount' => 3000,
        'post_date' => now(),
    ]);

    $office = collect(
        Livewire::actingAs($user)
            ->test(CategoryReport::class)
            ->instance()
            ->expenses()['buckets']
    )->firstWhere('name', 'Office');

    expect($office['total'])->toBe(5000)
        ->and(collect($office['children'])->pluck('id')->all())->toBe([$child->id])
        ->and(collect($office['children'])->pluck('path')->all())->toBe(['Office / Software']);
});
