<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Casts\MoneyCast;
use App\Livewire\CategoryReport;
use App\Models\Account;
use App\Models\Category;
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

test('authenticated user sees the reports page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertOk()
        ->assertSee('data-testid="category-report"', false);
});

test('rolls a sub-category transaction up to its root at the top level', function () {
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
        ->assertDontSee('Food Retailing')
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

test('drilling into a root splits children from root-direct general spend', function () {
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

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $root->id,
        'amount' => 1500,
        'post_date' => now(),
    ]);

    $component = Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->call('drillInto', $root->id)
        ->assertSet('parent', $root->id)
        ->assertSee('Food Retailing')
        ->assertSee('General');

    $buckets = collect($component->instance()->report()['buckets']);

    expect($buckets->firstWhere('name', 'Food Retailing')['total'])->toBe(6000)
        ->and($buckets->firstWhere('name', 'General')['total'])->toBe(1500);
});

test('drilling up returns to the parent level', function () {
    $user = User::factory()->create();
    $root = Category::factory()->create(['name' => 'Retail Trade']);
    $child = Category::factory()->withParent($root)->create(['name' => 'Food Retailing']);

    Livewire::actingAs($user)
        ->test(CategoryReport::class)
        ->call('drillInto', $child->id)
        ->assertSet('parent', $child->id)
        ->call('drillUp')
        ->assertSet('parent', $root->id);
});

test('direction toggle switches between outgoing and incoming categories', function () {
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
        ->assertDontSee('Salary')
        ->set('direction', 'incoming')
        ->assertSee('Salary')
        ->assertDontSee('Dining');
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
        ->assertDontSee(MoneyCast::format(5000))
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

test('summary strip reports in, out and net across both directions', function () {
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
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -100000,
        'post_date' => now(),
    ]);

    $component = Livewire::actingAs($user)->test(CategoryReport::class);

    expect($component->instance()->summary())->toBe(['in' => 500000, 'out' => 300000, 'net' => 200000]);

    $component->assertSee(MoneyCast::format(500000))
        ->assertSee(MoneyCast::format(300000))
        ->assertSee(MoneyCast::format(200000));
});

test('invalid url state is normalised on mount', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->withQueryParams(['period' => 'bogus', 'direction' => 'sideways', 'parent' => 999999])
        ->test(CategoryReport::class)
        ->assertSet('period', 'this-month')
        ->assertSet('direction', 'outgoing')
        ->assertSet('parent', null);
});

test('reports is linked from the sidebar and the mobile more menu', function () {
    $user = User::factory()->create();

    $html = (string) $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->toContain(route('reports'));

    preg_match('/<nav[^>]*data-testid="mobile-tabbar"[\s\S]*?<\/nav>/', $html, $matches);

    expect($matches[0] ?? '')->toContain(route('reports'));
});
