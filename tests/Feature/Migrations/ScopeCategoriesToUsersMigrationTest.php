<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BnplOrderEventType;
use App\Enums\BudgetTag;
use App\Enums\SuggestionType;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\BnplOrder;
use App\Models\BnplOrderEvent;
use App\Models\Budget;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Payee;
use App\Models\PipelineRun;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Models\UserCategoryBudgetTag;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function scopeCategoriesMigration(): object
{
    return require database_path('migrations/2026_10_31_000100_scope_categories_to_users.php');
}

function allowGlobalCategories(): void
{
    (require database_path('migrations/2026_10_31_000200_make_user_id_required_on_categories_and_category_groups.php'))->down();
}

function globalGroup(string $name): int
{
    return (int) DB::table('category_groups')->insertGetId([
        'user_id' => null,
        'name' => $name,
        'color' => '#6471eb',
        'icon' => 'sparkles',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function globalCategory(string $name, ?int $parentId = null, ?int $groupId = null, ?string $budgetTag = null): int
{
    return (int) DB::table('categories')->insertGetId([
        'user_id' => null,
        'name' => $name,
        'parent_id' => $parentId,
        'group_id' => $groupId,
        'budget_tag' => $budgetTag,
        'icon' => 'home',
        'color' => '#112233',
        'is_hidden' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return array{food: int, groceries: int, organic: int} */
function globalGroceryTree(): array
{
    $food = globalGroup('Food');
    $groceries = globalCategory('Groceries', null, $food, 'needs');
    $organic = globalCategory('Organic', $groceries);

    return ['food' => $food, 'groceries' => $groceries, 'organic' => $organic];
}

/** @param array{food: int, groceries: int, organic: int} $tree */
function seedLedgerIn(User $user, array $tree): array
{
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->create(['category_id' => $tree['groceries']]);
    $split = TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $tree['organic']]);
    $budget = Budget::factory()->for($user)->create(['category_id' => $tree['groceries'], 'name' => 'Groceries']);
    $planned = PlannedTransaction::factory()->for($user)->for($account)->create(['category_id' => $tree['groceries']]);
    $order = BnplOrder::factory()->for($user)->create(['category_id' => $tree['groceries']]);
    $event = BnplOrderEvent::factory()->for($order)->create([
        'event' => BnplOrderEventType::CategorySet,
        'payload' => ['category_id' => $tree['groceries'], 'previous_category_id' => (string) $tree['organic']],
    ]);
    $payee = Payee::factory()->for($user)->create([
        'suggested_category_id' => $tree['groceries'],
        'confirmed_category_id' => $tree['organic'],
        'probabilities' => [$tree['groceries'] => 0.7, $tree['organic'] => 0.3],
    ]);
    $tag = UserCategoryBudgetTag::query()->create([
        'user_id' => $user->id,
        'category_id' => $tree['groceries'],
        'budget_tag' => BudgetTag::Wants,
    ]);
    $rule = UserRule::factory()->for($user)->for(UserRuleGroup::factory()->for($user), 'group')->create([
        'triggers' => [['field' => 'category_id', 'operator' => 'is', 'value' => (string) $tree['groceries']]],
        'actions' => [['type' => 'set_category', 'value' => (string) $tree['groceries']]],
    ]);
    $run = PipelineRun::factory()->for($user)->create();
    $suggestion = AnalysisSuggestion::factory()->create([
        'pipeline_run_id' => $run->id,
        'user_id' => $user->id,
        'type' => SuggestionType::UserRule,
        'payload' => [
            'category_id' => $tree['groceries'],
            'actions' => [['type' => 'set_category', 'value' => $tree['organic']]],
        ],
    ]);

    return compact('transaction', 'split', 'budget', 'planned', 'order', 'event', 'payee', 'tag', 'rule', 'suggestion');
}

function pathIn(User $user, ?int $categoryId): ?string
{
    return Category::allWithLinkedParents($user->id)->firstWhere('id', $categoryId)?->fullPath();
}

it('gives each user their own category tree with references rewritten', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    allowGlobalCategories();
    $tree = globalGroceryTree();
    $firstRows = seedLedgerIn($first, $tree);
    $secondRows = seedLedgerIn($second, $tree);

    scopeCategoriesMigration()->up();

    $mine = fn (string $model, int $id) => $model::query()->findOrFail($id)->fresh();

    expect(Category::query()->whereNull('user_id')->count())->toBe(0)
        ->and(Category::query()->where('user_id', $first->id)->count())->toBe(2)
        ->and(Category::query()->where('user_id', $second->id)->count())->toBe(2)
        ->and($mine(Transaction::class, $firstRows['transaction']->id)->category_id)->toBe($tree['groceries'])
        ->and($mine(Payee::class, $firstRows['payee']->id)->probabilities)->toBe([$tree['groceries'] => 0.7, $tree['organic'] => 0.3])
        ->and($mine(Payee::class, $firstRows['payee']->id)->confirmed_category_id)->toBe($tree['organic'])
        ->and($mine(TransactionSplit::class, $firstRows['split']->id)->category_id)->toBe($tree['organic'])
        ->and($mine(Budget::class, $firstRows['budget']->id)->category_id)->toBe($tree['groceries'])
        ->and($mine(PlannedTransaction::class, $firstRows['planned']->id)->category_id)->toBe($tree['groceries'])
        ->and($mine(BnplOrder::class, $firstRows['order']->id)->category_id)->toBe($tree['groceries'])
        ->and($mine(BnplOrderEvent::class, $firstRows['event']->id)->payload['category_id'])->toBe($tree['groceries'])
        ->and($mine(UserCategoryBudgetTag::class, $firstRows['tag']->id)->category_id)->toBe($tree['groceries'])
        ->and($mine(UserRule::class, $firstRows['rule']->id)->actions[0]['value'])->toBe((string) $tree['groceries'])
        ->and($mine(AnalysisSuggestion::class, $firstRows['suggestion']->id)->payload['actions'][0]['value'])->toBe($tree['organic']);

    $transaction = $mine(Transaction::class, $secondRows['transaction']->id);
    $payee = $mine(Payee::class, $secondRows['payee']->id);
    $rule = $mine(UserRule::class, $secondRows['rule']->id);
    $suggestion = $mine(AnalysisSuggestion::class, $secondRows['suggestion']->id);
    $event = $mine(BnplOrderEvent::class, $secondRows['event']->id);

    expect($transaction->category_id)->not->toBe($tree['groceries'])
        ->and(pathIn($second, $transaction->category_id))->toBe('Groceries')
        ->and($transaction->category_source)->toBe($secondRows['transaction']->category_source)
        ->and(pathIn($second, $mine(TransactionSplit::class, $secondRows['split']->id)->category_id))->toBe('Groceries / Organic')
        ->and(pathIn($second, $mine(Budget::class, $secondRows['budget']->id)->category_id))->toBe('Groceries')
        ->and(pathIn($second, $mine(PlannedTransaction::class, $secondRows['planned']->id)->category_id))->toBe('Groceries')
        ->and(pathIn($second, $mine(BnplOrder::class, $secondRows['order']->id)->category_id))->toBe('Groceries')
        ->and(pathIn($second, $payee->suggested_category_id))->toBe('Groceries')
        ->and(pathIn($second, $payee->confirmed_category_id))->toBe('Groceries / Organic')
        ->and(collect($payee->probabilities)->keys()->map(fn (int|string $id): ?string => pathIn($second, (int) $id))->all())->toBe(['Groceries', 'Groceries / Organic'])
        ->and(collect($payee->probabilities)->values()->all())->toBe([0.7, 0.3])
        ->and(pathIn($second, $mine(UserCategoryBudgetTag::class, $secondRows['tag']->id)->category_id))->toBe('Groceries');

    expect($rule->triggers[0]['value'])->toBeString()
        ->and(pathIn($second, (int) $rule->triggers[0]['value']))->toBe('Groceries')
        ->and($rule->actions[0]['value'])->toBeString()
        ->and(pathIn($second, (int) $rule->actions[0]['value']))->toBe('Groceries')
        ->and(pathIn($second, $suggestion->payload['category_id']))->toBe('Groceries')
        ->and($suggestion->payload['actions'][0]['value'])->toBeInt()
        ->and(pathIn($second, $suggestion->payload['actions'][0]['value']))->toBe('Groceries / Organic')
        ->and(pathIn($second, $event->payload['category_id']))->toBe('Groceries')
        ->and($event->payload['previous_category_id'])->toBeString()
        ->and(pathIn($second, (int) $event->payload['previous_category_id']))->toBe('Groceries / Organic');
});

it('assigns existing categories to a sole user without rewriting rows', function () {
    $user = User::factory()->create();
    allowGlobalCategories();
    $tree = globalGroceryTree();
    seedLedgerIn($user, $tree);
    $ledgerBefore = [
        DB::table('transactions')->get()->all(),
        DB::table('transaction_splits')->get()->all(),
        DB::table('budgets')->get()->all(),
        DB::table('planned_transactions')->get()->all(),
        DB::table('payees')->get()->all(),
        DB::table('user_rules')->get()->all(),
        DB::table('analysis_suggestions')->get()->all(),
        DB::table('bnpl_orders')->get()->all(),
        DB::table('bnpl_order_events')->get()->all(),
    ];
    $categoryIds = DB::table('categories')->orderBy('id')->pluck('id')->all();

    expect(User::query()->count())->toBe(1);

    scopeCategoriesMigration()->up();

    $ledgerAfter = [
        DB::table('transactions')->get()->all(),
        DB::table('transaction_splits')->get()->all(),
        DB::table('budgets')->get()->all(),
        DB::table('planned_transactions')->get()->all(),
        DB::table('payees')->get()->all(),
        DB::table('user_rules')->get()->all(),
        DB::table('analysis_suggestions')->get()->all(),
        DB::table('bnpl_orders')->get()->all(),
        DB::table('bnpl_order_events')->get()->all(),
    ];

    expect($ledgerAfter)->toEqual($ledgerBefore)
        ->and(DB::table('categories')->orderBy('id')->pluck('id')->all())->toBe($categoryIds)
        ->and(DB::table('categories')->where('user_id', '!=', $user->id)->orWhereNull('user_id')->count())->toBe(0)
        ->and(DB::table('category_groups')->where('user_id', $user->id)->count())->toBe(1);
});

it('maps cloned parents and groups within the user', function () {
    $users = User::factory()->count(3)->create();
    allowGlobalCategories();
    $food = globalGroup('Food');
    $bills = globalGroup('Bills');
    $leaf = globalCategory('Leaf', null, $food);
    $branch = globalCategory('Branch', null, $bills);
    $root = globalCategory('Root');
    DB::table('categories')->where('id', $branch)->update(['parent_id' => $root]);
    DB::table('categories')->where('id', $leaf)->update(['parent_id' => $branch]);

    scopeCategoriesMigration()->up();

    foreach ($users as $user) {
        $owned = Category::query()->where('user_id', $user->id)->get()->keyBy('id');

        expect($owned)->toHaveCount(3)
            ->and(CategoryGroup::query()->where('user_id', $user->id)->pluck('name')->sort()->values()->all())->toBe(['Bills', 'Food'])
            ->and(pathIn($user, $owned->firstWhere('name', 'Leaf')->id))->toBe('Root / Branch / Leaf');

        foreach ($owned as $category) {
            expect($category->parent_id === null || $owned->has($category->parent_id))->toBeTrue()
                ->and($category->group_id === null || CategoryGroup::query()->whereKey($category->group_id)->value('user_id') === $user->id)->toBeTrue();
        }

        expect(CategoryGroup::query()->whereKey($owned->firstWhere('name', 'Leaf')->group_id)->value('name'))->toBe('Food')
            ->and(CategoryGroup::query()->whereKey($owned->firstWhere('name', 'Branch')->group_id)->value('name'))->toBe('Bills');
    }
});

it('resumes the category scoping migration', function () {
    [$first, $second, $third] = User::factory()->count(3)->create()->all();
    allowGlobalCategories();
    $tree = globalGroceryTree();
    seedLedgerIn($first, $tree);
    $secondRows = seedLedgerIn($second, $tree);
    $thirdRows = seedLedgerIn($third, $tree);
    $strayGroup = CategoryGroup::query()->create(['user_id' => $third->id, 'name' => 'Food', 'color' => '#6471eb', 'icon' => 'sparkles', 'sort_order' => 0]);

    expect(fn () => scopeCategoriesMigration()->up())->toThrow(QueryException::class);

    $secondClone = Category::query()->where('user_id', $second->id)->pluck('id')->all();

    expect($secondClone)->toHaveCount(2)
        ->and(Category::query()->where('user_id', $third->id)->count())->toBe(0)
        ->and(Category::query()->whereNull('user_id')->count())->toBe(2)
        ->and(pathIn($second, $secondRows['transaction']->fresh()->category_id))->toBe('Groceries')
        ->and($thirdRows['transaction']->fresh()->category_id)->toBe($tree['groceries']);

    $strayGroup->delete();

    scopeCategoriesMigration()->up();

    expect(Category::query()->where('user_id', $second->id)->pluck('id')->all())->toBe($secondClone)
        ->and(Category::query()->where('user_id', $third->id)->count())->toBe(2)
        ->and(Category::query()->whereNull('user_id')->count())->toBe(0)
        ->and(Category::query()->where('user_id', $first->id)->count())->toBe(2)
        ->and(pathIn($third, $thirdRows['transaction']->fresh()->category_id))->toBe('Groceries')
        ->and(pathIn($third, $thirdRows['split']->fresh()->category_id))->toBe('Groceries / Organic')
        ->and(pathIn($second, $secondRows['transaction']->fresh()->category_id))->toBe('Groceries');
});

it('refuses to roll back instead of leaving per-user trees half merged', function () {
    expect(fn () => scopeCategoriesMigration()->down())->toThrow(RuntimeException::class);
});
