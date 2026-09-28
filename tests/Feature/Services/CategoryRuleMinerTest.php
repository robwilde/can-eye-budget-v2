<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\CategoryRuleMiner;
use App\Services\CategorySeedRules;
use App\Services\RuleEvaluator;

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

it('rejects a candidate whose substring also matches a manual row filed elsewhere that no single trigger separates', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $coffee = Category::factory()->create(['name' => 'Groceries']);
    $cafe = Category::factory()->create(['name' => 'Dining']);

    // Same direction, same account, and the cafe amount sits between the
    // coffee amounts: nothing but the description tells them apart.
    foreach ([500, 2000] as $amount) {
        minerTransaction($user, $account, 'ACME COFFEE  BRISBANE', $coffee->id)->update(['amount' => $amount]);
    }
    minerTransaction($user, $account, 'ACME COFFEE HOUSE CAFE  SYDNEY', $cafe->id)->update(['amount' => 1000]);

    $result = app(CategoryRuleMiner::class)->mine($user);

    $contradiction = collect($result['contradictions'])->firstWhere('value', 'ACME COFFEE');

    expect(array_column($result['candidates'], 'value'))->not->toContain('ACME COFFEE')
        ->and($contradiction)->not->toBeNull()
        ->and($contradiction['category_id'])->toBe($coffee->id)
        ->and($contradiction['source'])->toBe('mined')
        ->and($contradiction['contradicting'])->toBe(1)
        ->and($contradiction['contradicting_categories'])->toBe([$cafe->fullPath() => 1]);
});

it('narrows a contradicted candidate by direction when that separates the manual rows exactly', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $coffee = Category::factory()->create(['name' => 'Groceries']);
    $refunds = Category::factory()->create(['name' => 'Shopping']);

    $purchase = minerTransaction($user, $account, 'ACME COFFEE  BRISBANE', $coffee->id);
    $refund = minerTransaction($user, $account, 'ACME COFFEE REFUND  BRISBANE', $refunds->id);
    $refund->update(['direction' => TransactionDirection::Credit]);

    $miner = app(CategoryRuleMiner::class);
    $result = $miner->mine($user);

    $candidate = collect($result['candidates'])->firstWhere('value', 'ACME COFFEE');

    expect($candidate)->not->toBeNull()
        ->and($candidate['extra_triggers'])->toBe([['field' => 'direction', 'operator' => 'is', 'value' => 'debit']])
        ->and(array_column($result['contradictions'], 'value'))->not->toContain('ACME COFFEE');

    $miner->createRules($user, [$candidate]);
    $rule = UserRule::query()->where('user_id', $user->id)->sole();
    $evaluator = app(RuleEvaluator::class);

    // A later feed row shaped like the refund must not be pulled into Groceries.
    $incomingRefund = Transaction::factory()->for($user)->for($account)->create([
        'description' => 'ACME COFFEE REFUND  SYDNEY',
        'direction' => TransactionDirection::Credit,
        'category_id' => null,
    ]);

    expect($evaluator->matches($purchase->fresh(), $rule))->toBeTrue()
        ->and($evaluator->matches($refund->fresh(), $rule))->toBeFalse()
        ->and($evaluator->matches($incomingRefund, $rule))->toBeFalse();
});

it('narrows by account when direction does not separate the manual rows', function () {
    $user = User::factory()->create();
    $personal = Account::factory()->for($user)->create();
    $business = Account::factory()->for($user)->create();
    $coffee = Category::factory()->create(['name' => 'Groceries']);
    $office = Category::factory()->create(['name' => 'Utilities']);

    minerTransaction($user, $personal, 'ACME COFFEE  BRISBANE', $coffee->id);
    minerTransaction($user, $business, 'ACME COFFEE WHOLESALE  BRISBANE', $office->id);

    $candidate = collect(app(CategoryRuleMiner::class)->mine($user)['candidates'])->firstWhere('value', 'ACME COFFEE');

    expect($candidate['extra_triggers'])->toBe([['field' => 'account_id', 'operator' => 'is', 'value' => (string) $personal->id]]);
});

it('narrows by an amount threshold at the midpoint of the gap when only amounts separate the rows', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $coffee = Category::factory()->create(['name' => 'Groceries']);
    $equipment = Category::factory()->create(['name' => 'Shopping']);

    minerTransaction($user, $account, 'ACME COFFEE  BRISBANE', $coffee->id)->update(['amount' => 450]);
    minerTransaction($user, $account, 'ACME COFFEE  SYDNEY', $coffee->id)->update(['amount' => 650]);
    minerTransaction($user, $account, 'ACME COFFEE MACHINES  SYDNEY', $equipment->id)->update(['amount' => 89900]);

    $candidate = collect(app(CategoryRuleMiner::class)->mine($user)['candidates'])->firstWhere('value', 'ACME COFFEE');

    expect($candidate['extra_triggers'])->toBe([['field' => 'amount', 'operator' => 'less_than_or_equal', 'value' => '45275']]);
});

it('does not create a seed rule that contradicts a manual categorisation', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $entertainment = Category::factory()->create(['name' => 'Entertainment']);
    Category::factory()->withParent($entertainment)->create(['name' => 'Streaming']);
    $other = Category::factory()->create(['name' => 'Education']);

    Transaction::factory()->for($user)->for($account)->create([
        'description' => 'PRIMEVIDEO  COURSE',
        'category_id' => $other->id,
        'category_source' => CategorySource::Manual,
    ]);

    $result = app(CategoryRuleMiner::class)->mine($user);

    $contradiction = collect($result['contradictions'])->firstWhere('value', 'PRIMEVIDEO');

    expect(array_column($result['candidates'], 'value'))->not->toContain('PRIMEVIDEO')
        ->and($contradiction)->not->toBeNull()
        ->and($contradiction['source'])->toBe('seed')
        ->and($contradiction['contradicting_categories'])->toBe([$other->fullPath() => 1]);
});
