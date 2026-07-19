<?php

declare(strict_types=1);

use App\Console\Commands\MineCategoryRulesCommand;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use Carbon\CarbonImmutable;
use ReflectionClass;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::create(2026, 6, 15));

    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    ensureSeedCategories();
});

function mineRulesArgs(User $user, Account $account, array $extra = []): array
{
    return array_merge(['--user' => $user->id, '--account' => [$account->id]], $extra);
}

function categorised(User $user, Account $account, string $description, int $categoryId): Transaction
{
    return Transaction::factory()->for($user)->for($account)->manual()->create([
        'description' => $description,
        'category_id' => $categoryId,
        'post_date' => '2026-06-10',
    ]);
}

function triggerValues(): Illuminate\Support\Collection
{
    return UserRule::all()->map(fn (UserRule $rule): string => $rule->triggers[0]['value'] ?? '');
}

function ensureSeedCategories(): void
{
    $seeds = (new ReflectionClass(MineCategoryRulesCommand::class))->getConstant('SEED_RULES');

    foreach (array_unique(array_values($seeds)) as $id) {
        if (Category::query()->whereKey($id)->exists()) {
            continue;
        }

        $category = new Category(['name' => 'Seed category '.$id, 'is_hidden' => false]);
        $category->id = $id;
        $category->save();
    }
}

test('mines an unambiguous merchant into a rule and categorises the existing sibling', function () {
    $category = Category::factory()->create();
    categorised($this->user, $this->account, 'VISA -QUOKKA LABS             CITY AU  111111 #7173', $category->id);
    $sibling = Transaction::factory()->for($this->user)->for($this->account)->manual()->create([
        'description' => 'VISA -QUOKKA LABS             CITY AU  222222 #7173',
        'category_id' => null,
        'post_date' => '2026-06-11',
    ]);

    $this->artisan('categories:mine-rules', mineRulesArgs($this->user, $this->account))->assertSuccessful();

    $created = UserRule::all()->first(fn (UserRule $r): bool => ($r->triggers[0]['value'] ?? '') === 'QUOKKA LABS');

    expect($created)->not->toBeNull()
        ->and($created->actions[0]['value'])->toBe((string) $category->id)
        ->and($created->is_auto_apply)->toBeTrue()
        ->and($created->strict_mode)->toBeTrue()
        ->and($sibling->fresh()->category_id)->toBe($category->id);
});

test('reports a merchant with two categories as ambiguous and creates no rule', function () {
    $catA = Category::factory()->create();
    $catB = Category::factory()->create();
    categorised($this->user, $this->account, 'VISA -NUMBAT CO               CITY AU  111111 #7173', $catA->id);
    categorised($this->user, $this->account, 'VISA -NUMBAT CO               CITY AU  222222 #7173', $catB->id);

    $this->artisan('categories:mine-rules', mineRulesArgs($this->user, $this->account))
        ->expectsOutputToContain('Ambiguous merchants (skipped): 1')
        ->assertSuccessful();

    expect(triggerValues()->contains('NUMBAT CO'))->toBeFalse();
});

test('does not duplicate a rule that already covers the merchant with the same category', function () {
    $category = Category::factory()->create();
    $group = UserRuleGroup::factory()->for($this->user)->create();
    UserRule::factory()->autoApply()->create([
        'user_id' => $this->user->id,
        'user_rule_group_id' => $group->id,
        'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => 'WALLABY MART']],
        'actions' => [['type' => 'set_category', 'value' => (string) $category->id]],
    ]);
    categorised($this->user, $this->account, 'VISA -WALLABY MART            CITY AU  111111 #7173', $category->id);

    $this->artisan('categories:mine-rules', mineRulesArgs($this->user, $this->account))->assertSuccessful();

    expect(triggerValues()->filter(fn (string $v): bool => str_contains($v, 'WALLABY MART')))->toHaveCount(1);
});

test('reports a conflicting rule and creates no rule for that merchant', function () {
    $catA = Category::factory()->create();
    $catB = Category::factory()->create();
    $group = UserRuleGroup::factory()->for($this->user)->create();
    UserRule::factory()->autoApply()->create([
        'user_id' => $this->user->id,
        'user_rule_group_id' => $group->id,
        'triggers' => [['field' => 'description', 'operator' => 'contains', 'value' => 'DINGO GEAR']],
        'actions' => [['type' => 'set_category', 'value' => (string) $catB->id]],
    ]);
    categorised($this->user, $this->account, 'VISA -DINGO GEAR              CITY AU  111111 #7173', $catA->id);

    $this->artisan('categories:mine-rules', mineRulesArgs($this->user, $this->account))
        ->expectsOutputToContain('Conflicts (skipped): 1')
        ->assertSuccessful();

    $dingo = UserRule::all()->filter(fn (UserRule $r): bool => str_contains($r->triggers[0]['value'] ?? '', 'DINGO GEAR'));

    expect($dingo)->toHaveCount(1)
        ->and($dingo->first()->actions[0]['value'])->toBe((string) $catB->id);
});

test('dry run writes no rules and leaves transactions uncategorised', function () {
    $category = Category::factory()->create();
    categorised($this->user, $this->account, 'VISA -QUOKKA LABS             CITY AU  111111 #7173', $category->id);
    $sibling = Transaction::factory()->for($this->user)->for($this->account)->manual()->create([
        'description' => 'VISA -QUOKKA LABS             CITY AU  222222 #7173',
        'category_id' => null,
        'post_date' => '2026-06-11',
    ]);

    $before = UserRule::count();

    $this->artisan('categories:mine-rules', mineRulesArgs($this->user, $this->account, ['--dry-run' => true]))
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(UserRule::count())->toBe($before)
        ->and($sibling->fresh()->category_id)->toBeNull();
});

test('creates a rule from the curated seed list', function () {
    $this->artisan('categories:mine-rules', mineRulesArgs($this->user, $this->account))->assertSuccessful();

    expect(triggerValues()->contains('PRIMEVIDEO'))->toBeTrue();
});

test('aborts without writing when a referenced category is missing', function () {
    Category::query()->whereKey(35)->delete();
    $before = UserRule::count();

    $this->artisan('categories:mine-rules', mineRulesArgs($this->user, $this->account))
        ->expectsOutputToContain('Unknown category id(s)')
        ->assertFailed();

    expect(UserRule::count())->toBe($before);
});
