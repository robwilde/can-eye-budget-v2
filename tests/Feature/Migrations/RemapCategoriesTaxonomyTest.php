<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BnplOrderEventType;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\BnplOrderEvent;
use App\Models\Category;
use App\Models\PipelineRun;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Models\UserRule;
use Illuminate\Support\Facades\DB;

function legacyCategory(string $name, ?Category $parent = null): Category
{
    return Category::factory()->create(['name' => $name, 'parent_id' => $parent?->id, 'is_hidden' => false]);
}

/** @return array<string, Category> keyed by "Root" or "Root|Child" */
function legacyTree(): array
{
    $tree = [];

    foreach ([
        'Office' => ['Online Service', 'Software', 'AI Apps', 'Mobile App', 'Hardware', '3D Printing', 'IoT', 'Laptop', 'Tools', 'Newsletter', 'Training'],
        'Personal' => ['Health', 'Subscription', 'Finance', 'Hunter', 'Pet', 'Kitchen', 'Clothes', 'Gifts', 'Grooming', 'Beddings', 'Bathroom', 'Holiday', 'Plants', 'Fines', 'Charity'],
        'Food' => ['Groceries', 'Restaurant', 'Quick Foods'],
        'Bills' => ['Rent', 'Cleaning', 'Mobile', 'Internet', 'Electricity', 'Hotwater', 'Food'],
        'Loan' => ['Motorcycle', 'Latitude', 'Shane'],
        'Transport' => ['Uber', 'Tolls'],
        'Entertainment' => ['Streaming'],
        'Income' => ['Salary'],
        'Transfer' => ['Optimus to CC'],
    ] as $rootName => $children) {
        $root = legacyCategory($rootName);
        $tree[$rootName] = $root;

        foreach ($children as $child) {
            $tree[$rootName.'|'.$child] = legacyCategory($child, $root);
        }
    }

    $tree['Personal|Finance|Bank Fees'] = legacyCategory('Bank Fees', $tree['Personal|Finance']);

    return $tree;
}

function runTaxonomyMigration(): void
{
    (require database_path('migrations/2026_10_10_000100_remap_categories_to_budget_tag_taxonomy.php'))->up();
}

function pathsById(): array
{
    return Category::allWithLinkedParents()->mapWithKeys(fn (Category $category): array => [$category->id => $category->fullPath()])->all();
}

test('re-parents old leaves under the budget-tagged roots keeping their ids', function () {
    $tree = legacyTree();

    runTaxonomyMigration();

    $paths = pathsById();

    expect($paths[$tree['Office|AI Apps']->id])->toBe('Software & Online Services / AI Apps')
        ->and($paths[$tree['Office|Training']->id])->toBe('Learning & Reading / Training')
        ->and($paths[$tree['Office|Hardware']->id])->toBe('Work Equipment / Hardware')
        ->and($paths[$tree['Bills|Rent']->id])->toBe('Housing & Utilities / Rent')
        ->and($paths[$tree['Food|Quick Foods']->id])->toBe('Eating Out / Quick Foods')
        ->and($paths[$tree['Personal|Charity']->id])->toBe('Personal & Shopping / Charity')
        ->and($paths[$tree['Personal|Health']->id])->toBe('Health')
        ->and($paths[$tree['Personal|Pet']->id])->toBe('Pets')
        ->and($paths[$tree['Personal|Finance']->id])->toBe('Bank Fees & Finance Services')
        ->and($paths[$tree['Personal|Finance|Bank Fees']->id])->toBe('Bank Fees & Finance Services / Bank Fees')
        ->and($paths[$tree['Loan']->id])->toBe('Loans & Debt Repayment')
        ->and($paths[$tree['Loan|Latitude']->id])->toBe('Loans & Debt Repayment / Latitude')
        ->and($paths[$tree['Income|Salary']->id])->toBe('Income / Salary')
        ->and($paths[$tree['Transfer|Optimus to CC']->id])->toBe('Transfer / Optimus to CC');
});

test('tags exactly the 14 debit roots and deletes the emptied old roots', function () {
    legacyTree();

    runTaxonomyMigration();

    $roots = Category::query()->whereNull('parent_id')->get()->keyBy('name');

    expect($roots->keys()->sort()->values()->all())->toBe([
        'Bank Fees & Finance Services',
        'Eating Out',
        'Entertainment',
        'Groceries',
        'Health',
        'Housing & Utilities',
        'Income',
        'Insurance',
        'Learning & Reading',
        'Loans & Debt Repayment',
        'Personal & Shopping',
        'Pets',
        'Software & Online Services',
        'Transfer',
        'Transport',
        'Work Equipment',
    ])->and($roots->only(['Income', 'Transfer'])->pluck('budget_tag')->filter()->isEmpty())->toBeTrue()
        ->and($roots['Groceries']->budget_tag->value)->toBe('needs')
        ->and($roots['Work Equipment']->budget_tag->value)->toBe('wants')
        ->and($roots['Loans & Debt Repayment']->budget_tag->value)->toBe('savings')
        ->and($roots['Transport']->budget_tag->value)->toBe('needs')
        ->and($roots['Entertainment']->budget_tag->value)->toBe('wants')
        ->and(Category::query()->whereNotNull('parent_id')->whereNotNull('budget_tag')->exists())->toBeFalse();
});

test('merging Bills / Food and the Food root into Groceries rewrites every reference and orphans nothing', function () {
    $tree = legacyTree();
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $billsFood = $tree['Bills|Food'];
    $foodRoot = $tree['Food'];
    $groceries = $tree['Food|Groceries'];

    $onBillsFood = Transaction::factory()->for($user)->for($account)->create(['category_id' => $billsFood->id]);
    $onFoodRoot = Transaction::factory()->for($user)->for($account)->create(['category_id' => $foodRoot->id]);
    $onGroceries = Transaction::factory()->for($user)->for($account)->create(['category_id' => $groceries->id]);
    $split = TransactionSplit::factory()->create(['transaction_id' => $onGroceries->id, 'category_id' => $billsFood->id]);

    $rule = UserRule::factory()->create([
        'user_id' => $user->id,
        'actions' => [['type' => 'set_category', 'value' => (string) $billsFood->id]],
        'triggers' => [['field' => 'category_id', 'operator' => 'equals', 'value' => (string) $foodRoot->id]],
    ]);

    $run = PipelineRun::factory()->create(['user_id' => $user->id]);
    $suggestion = AnalysisSuggestion::factory()->create([
        'pipeline_run_id' => $run->id,
        'user_id' => $user->id,
        'payload' => ['category_id' => $billsFood->id, 'previous_category_id' => $foodRoot->id],
    ]);
    $bnplEvent = BnplOrderEvent::factory()->create([
        'event' => BnplOrderEventType::CategorySet,
        'payload' => ['category_id' => $billsFood->id, 'previous_category_id' => $foodRoot->id],
    ]);

    runTaxonomyMigration();

    $groceriesId = Category::query()->whereNull('parent_id')->where('name', 'Groceries')->value('id');

    expect($groceriesId)->toBe($groceries->id)
        ->and(Category::query()->whereKey([$billsFood->id, $foodRoot->id])->exists())->toBeFalse()
        ->and($onBillsFood->fresh()->category_id)->toBe($groceriesId)
        ->and($onFoodRoot->fresh()->category_id)->toBe($groceriesId)
        ->and($onGroceries->fresh()->category_id)->toBe($groceriesId)
        ->and($split->fresh()->category_id)->toBe($groceriesId)
        ->and($rule->fresh()->actions[0]['value'])->toBe((string) $groceriesId)
        ->and($rule->fresh()->triggers[0]['value'])->toBe((string) $groceriesId)
        ->and($suggestion->fresh()->payload['category_id'])->toBe($groceriesId)
        ->and($suggestion->fresh()->payload['previous_category_id'])->toBe($groceriesId)
        ->and($bnplEvent->fresh()->payload['category_id'])->toBe($groceriesId)
        ->and($bnplEvent->fresh()->payload['previous_category_id'])->toBe($groceriesId);

    $existing = Category::query()->pluck('id')->all();

    expect(Transaction::query()->whereNotNull('category_id')->pluck('category_id')->diff($existing)->all())->toBe([])
        ->and(TransactionSplit::query()->whereNotNull('category_id')->pluck('category_id')->diff($existing)->all())->toBe([])
        ->and(Category::query()->whereNotNull('parent_id')->pluck('parent_id')->diff($existing)->all())->toBe([]);
});

test('keeps an old root that still has direct references', function () {
    $tree = legacyTree();
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->for($account)->create(['category_id' => $tree['Personal']->id]);

    runTaxonomyMigration();

    expect(Category::query()->whereKey($tree['Personal']->id)->exists())->toBeTrue()
        ->and($transaction->fresh()->category_id)->toBe($tree['Personal']->id)
        ->and(Category::query()->whereKey($tree['Office']->id)->exists())->toBeFalse();
});

test('running twice leaves the tree unchanged', function () {
    legacyTree();

    runTaxonomyMigration();
    $first = pathsById();
    runTaxonomyMigration();

    expect(pathsById())->toBe($first);
});

test('does nothing on a database with no categories so the seeder is the only source of a fresh tree', function () {
    runTaxonomyMigration();

    expect(DB::table('categories')->count())->toBe(0);
});
