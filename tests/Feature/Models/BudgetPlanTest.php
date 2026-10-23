<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\BudgetPlan;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

it('allows one budget plan per user per period', function () {
    $user = User::factory()->create();
    BudgetPlan::factory()->for($user)->create(['period_start' => '2026-10-01', 'period_end' => '2026-10-31']);

    expect(fn () => BudgetPlan::factory()->for($user)->create(['period_start' => '2026-10-01', 'period_end' => '2026-10-31']))
        ->toThrow(QueryException::class);

    BudgetPlan::factory()->for($user)->create(['period_start' => '2026-11-01', 'period_end' => '2026-11-30']);
    BudgetPlan::factory()->create(['period_start' => '2026-10-01', 'period_end' => '2026-10-31']);

    expect(BudgetPlan::query()->count())->toBe(3);
});

it('rejects a second line for the same category in one plan', function () {
    $plan = BudgetPlan::factory()->create();
    $groceries = Category::factory()->create();
    Budget::factory()->inPlan($plan)->withCategory($groceries)->create();

    expect(fn () => Budget::factory()->inPlan($plan)->withCategory($groceries)->create())
        ->toThrow(QueryException::class);

    Budget::factory()->inPlan(BudgetPlan::factory()->create())->withCategory($groceries)->create();

    expect(Budget::query()->where('category_id', $groceries->id)->count())->toBe(2);
});

it('deletes budget lines with their plan', function () {
    $doomed = BudgetPlan::factory()->create();
    $kept = BudgetPlan::factory()->create();
    Budget::factory()->count(2)->inPlan($doomed)->create();
    $keptLine = Budget::factory()->inPlan($kept)->create();

    $doomed->delete();

    expect(Budget::query()->pluck('id')->all())->toBe([$keptLine->id]);
});

it('stores plan total and expected income as integer cents', function () {
    $plan = BudgetPlan::factory()->create(['total_amount' => 200000, 'expected_income' => 300000]);

    $stored = DB::table('budget_plans')->where('id', $plan->id)->first();

    expect($stored->total_amount)->toBe(200000)
        ->and($stored->expected_income)->toBe(300000)
        ->and($plan->fresh()->total_amount)->toBe(200000);
});

it('scopes budget plans to their owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $mine = BudgetPlan::factory()->for($owner)->create(['period_start' => '2026-10-01', 'period_end' => '2026-10-31']);
    BudgetPlan::factory()->for($other)->create(['period_start' => '2026-10-01', 'period_end' => '2026-10-31']);

    expect($owner->budgetPlans()->pluck('id')->all())->toBe([$mine->id]);
});

it('names a plan line after its category', function () {
    $plan = BudgetPlan::factory()->create();
    $category = Category::factory()->create(['name' => 'Groceries']);

    $line = Budget::query()->create([
        'user_id' => $plan->user_id,
        'budget_plan_id' => $plan->id,
        'category_id' => $category->id,
        'limit_amount' => null,
        'period' => 'monthly',
        'start_date' => $plan->period_start,
    ]);

    $factoryLine = Budget::factory()->inPlan($plan)->withCategory(Category::factory()->create(['name' => 'Transport']))->create();

    expect($line->name)->toBe('Groceries')
        ->and($line->fresh()->limit_amount)->toBeNull()
        ->and($factoryLine->name)->toBe('Transport');
});

it('keeps budget plan index names within the MariaDB limit', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->andReturn('11.4.5-MariaDB');
    $connection = new MariaDbConnection($pdo, 'identifier_probe', '', ['name' => 'identifier_probe', 'driver' => 'mariadb']);
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());

    try {
        $queries = $connection->pretend(function () {
            (require database_path('migrations/2026_10_23_100000_create_budget_plans_table.php'))->up();
            (require database_path('migrations/2026_10_23_100100_add_budget_plan_columns_to_budgets_table.php'))->up();
        });
    } finally {
        Schema::swap($originalSchema);
    }

    $identifiers = collect($queries)
        ->flatMap(fn (array $query) => preg_match_all('/`([^`]+)`/', $query['query'], $matches) ? $matches[1] : [])
        ->unique();

    expect($identifiers->filter(fn (string $name) => str_contains($name, 'budget_plan')))->not->toBeEmpty()
        ->and($identifiers->filter(fn (string $name) => mb_strlen($name) > 64)->values()->all())->toBeEmpty();
});

it('rejects a negative rollover amount when a line is created or updated', function () {
    $plan = BudgetPlan::factory()->create();

    expect(fn () => Budget::factory()->inPlan($plan)->create(['rolled_over_amount' => -100]))
        ->toThrow(ValidationException::class);

    $line = Budget::factory()->inPlan($plan)->create(['rolled_over_amount' => 500]);

    expect(fn () => $line->update(['rolled_over_amount' => -1]))->toThrow(ValidationException::class)
        ->and($line->fresh()->rolled_over_amount)->toBe(500);

    $line->update(['rolled_over_amount' => 0]);

    expect($line->fresh()->rolled_over_amount)->toBe(0);
});

it('removes plan lines and restores the legacy budget shape when rolled back', function () {
    $user = User::factory()->create();
    $legacy = Budget::factory()->for($user)->create(['name' => 'Insurance', 'limit_amount' => 30000]);
    $plan = BudgetPlan::factory()->for($user)->create();
    Budget::factory()->inPlan($plan)->create(['limit_amount' => null]);

    (require database_path('migrations/2026_10_23_100100_add_budget_plan_columns_to_budgets_table.php'))->down();

    expect(Schema::hasColumn('budgets', 'budget_plan_id'))->toBeFalse()
        ->and(DB::table('budgets')->pluck('id')->all())->toBe([$legacy->id])
        ->and(DB::table('budgets')->where('id', $legacy->id)->value('limit_amount'))->toBe(30000)
        ->and(fn () => DB::table('budgets')->insert([
            'user_id' => $user->id,
            'name' => 'No limit',
            'limit_amount' => null,
            'period' => 'monthly',
            'start_date' => '2026-10-01',
        ]))->toThrow(QueryException::class);
});
