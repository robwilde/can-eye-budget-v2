<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\CategoryRuleMiner;
use App\Services\CategorySeedRules;

/**
 * Descriptions use a double-space separator so MerchantMatchValue::for() keys the
 * group on the leading merchant field, making the mined value deterministic and
 * independent of CategoryRuleGenerator's token-guessing fallback.
 */
function minerTransaction(User $user, Account $account, string $description, ?int $categoryId): Transaction
{
    return Transaction::factory()->for($user)->for($account)->manual()->create([
        'description' => $description,
        'direction' => TransactionDirection::Debit,
        'category_id' => $categoryId,
    ]);
}

it('mines a candidate from categorised history without invoking artisan', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Groceries']);

    minerTransaction($user, $account, 'ACME COFFEE ROASTERS  BRISBANE', $category->id);
    minerTransaction($user, $account, 'ACME COFFEE ROASTERS  SYDNEY', $category->id);

    $result = app(CategoryRuleMiner::class)->mine($user);

    $values = array_column($result['candidates'], 'value');
    expect($values)->toContain('ACME COFFEE ROASTERS');

    $candidate = collect($result['candidates'])->firstWhere('value', 'ACME COFFEE ROASTERS');
    expect($candidate['category_id'])->toBe($category->id)
        ->and($candidate['source'])->toBe('mined')
        ->and($result['ambiguous'])->toBe([])
        ->and($result['conflicts'])->toBe([]);
});

it('reports a merchant spanning two categories as ambiguous instead of a candidate', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $groceries = Category::factory()->create(['name' => 'Groceries']);
    $dining = Category::factory()->create(['name' => 'Dining']);

    minerTransaction($user, $account, 'ACME COFFEE ROASTERS  BRISBANE', $groceries->id);
    minerTransaction($user, $account, 'ACME COFFEE ROASTERS  SYDNEY', $dining->id);

    $result = app(CategoryRuleMiner::class)->mine($user);

    expect(array_column($result['candidates'], 'value'))->not->toContain('ACME COFFEE ROASTERS');

    $ambiguous = collect($result['ambiguous'])->firstWhere('value', 'ACME COFFEE ROASTERS');
    expect($ambiguous)->not->toBeNull()
        ->and($ambiguous['categories'])->toBe(collect([$groceries->id, $dining->id])->sort()->values()->all());
});

it('does not re-propose a merchant an active rule already categorises', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Groceries']);

    $group = UserRuleGroup::factory()->for($user)->create(['is_active' => true]);
    UserRule::factory()->for($user)->for($group, 'group')->create([
        'is_active' => true,
        'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => 'ACME COFFEE ROASTERS']],
        'actions' => [['type' => 'set_category', 'value' => (string) $category->id]],
    ]);

    minerTransaction($user, $account, 'ACME COFFEE ROASTERS  BRISBANE', $category->id);
    minerTransaction($user, $account, 'ACME COFFEE ROASTERS  SYDNEY', $category->id);

    $result = app(CategoryRuleMiner::class)->mine($user);

    expect(array_column($result['candidates'], 'value'))->not->toContain('ACME COFFEE ROASTERS')
        ->and(array_column($result['ambiguous'], 'value'))->not->toContain('ACME COFFEE ROASTERS')
        ->and(array_column($result['conflicts'], 'value'))->not->toContain('ACME COFFEE ROASTERS');
});

it('flags a merchant an active rule files under a different category as a conflict', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $mined = Category::factory()->create(['name' => 'Groceries']);
    $ruled = Category::factory()->create(['name' => 'Dining']);

    $group = UserRuleGroup::factory()->for($user)->create(['is_active' => true]);
    $rule = UserRule::factory()->for($user)->for($group, 'group')->create([
        'is_active' => true,
        'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => 'ACME COFFEE ROASTERS']],
        'actions' => [['type' => 'set_category', 'value' => (string) $ruled->id]],
    ]);

    minerTransaction($user, $account, 'ACME COFFEE ROASTERS  BRISBANE', $mined->id);

    $result = app(CategoryRuleMiner::class)->mine($user);

    $conflict = collect($result['conflicts'])->firstWhere('value', 'ACME COFFEE ROASTERS');
    expect($conflict)->not->toBeNull()
        ->and($conflict['candidate'])->toBe($mined->id)
        ->and($conflict['rule_category'])->toBe($ruled->id)
        ->and($conflict['rule_id'])->toBe($rule->id)
        ->and(array_column($result['candidates'], 'value'))->not->toContain('ACME COFFEE ROASTERS');
});

it('persists one auto-apply rule per entry in the Auto-categorisation group', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['name' => 'Groceries']);

    $entries = [
        ['value' => 'ACME COFFEE', 'category_id' => $category->id, 'source' => 'mined'],
        ['value' => 'BETA BAKERY', 'category_id' => $category->id, 'source' => 'seed'],
    ];

    $created = app(CategoryRuleMiner::class)->createRules($user, $entries);

    expect($created)->toBe(2);

    $group = UserRuleGroup::query()
        ->where('user_id', $user->id)
        ->where('name', CategoryRuleMiner::GROUP_NAME)
        ->sole();

    $rules = UserRule::query()->where('user_rule_group_id', $group->id)->orderBy('order')->get();

    expect($rules)->toHaveCount(2)
        ->and($rules->pluck('is_auto_apply')->all())->toBe([true, true])
        ->and($rules->pluck('is_active')->all())->toBe([true, true])
        ->and($rules[0]->triggers)->toBe([[
            'field' => 'description',
            'operator' => 'contains',
            'value' => 'ACME COFFEE',
        ]])
        ->and($rules[0]->actions)->toBe([['type' => 'set_category', 'value' => (string) $category->id]])
        ->and($rules->pluck('order')->all())->toBe([1, 2]);
});

it('does not re-propose entries once their rules exist, so a second mine writes nothing new', function () {
    // createRules() is an unconditional writer by design — the dedupe that makes
    // repeat runs safe lives in mine(), which filters candidates against the
    // trigger values of existing active rules. Idempotency is therefore asserted
    // at the mine() -> createRules() level, the way the command drives it.
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Groceries']);

    minerTransaction($user, $account, 'ACME COFFEE ROASTERS  BRISBANE', $category->id);
    minerTransaction($user, $account, 'ACME COFFEE ROASTERS  SYDNEY', $category->id);

    $miner = app(CategoryRuleMiner::class);

    $first = $miner->mine($user);
    $miner->createRules($user, $first['candidates']);

    $countAfterFirst = UserRule::query()->where('user_id', $user->id)->count();

    $second = $miner->mine($user);
    $miner->createRules($user, $second['candidates']);

    expect($countAfterFirst)->toBeGreaterThan(0)
        ->and($second['candidates'])->toBe([])
        ->and(UserRule::query()->where('user_id', $user->id)->count())->toBe($countAfterFirst);
});

it('reports curated seeds whose category path does not exist here as skipped', function () {
    $user = User::factory()->create();

    $result = app(CategoryRuleMiner::class)->mine($user);

    // Factory categories are single-segment, so none of the multi-segment curated
    // seed paths resolve: every seed must be reported rather than silently dropped,
    // naming both the merchant and the path that failed to resolve.
    expect($result['skippedSeeds'])->not->toBeEmpty()
        ->and($result['skippedSeeds'])->toContain('PRIMEVIDEO → Entertainment / Streaming')
        ->and($result['skippedSeeds'])->toHaveCount(CategorySeedRules::count())
        ->and($result['candidates'])->toBe([]);
});
