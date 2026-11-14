<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccount;
use App\Models\GoalPledge;
use App\Models\Transaction;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('deletes only links and pledges with a goal', function () {
    $goal = Goal::factory()->create();
    $first = Account::factory()->for($goal->user)->create();
    $second = Account::factory()->for($goal->user)->create();
    $transaction = Transaction::factory()->for($first)->create(['user_id' => $goal->user_id]);
    GoalAccount::factory()->for($goal)->for($first)->create();
    GoalAccount::factory()->for($goal)->for($second)->create();
    GoalPledge::factory()->for($goal)->for($first)->create(['matched_transaction_id' => $transaction->id]);

    $goal->delete();

    expect(GoalAccount::query()->count())->toBe(0)
        ->and(GoalPledge::query()->count())->toBe(0)
        ->and(Account::query()->whereKey([$first->id, $second->id])->count())->toBe(2)
        ->and(Transaction::query()->whereKey($transaction->id)->exists())->toBeTrue();
});

it('removes the goal link when an account is deleted', function () {
    $goal = Goal::factory()->create();
    $account = Account::factory()->for($goal->user)->create();
    GoalAccount::factory()->for($goal)->for($account)->create();

    $account->delete();

    expect(GoalAccount::query()->count())->toBe(0)
        ->and(Goal::query()->whereKey($goal->id)->exists())->toBeTrue();
});

it('lets a transaction be claimed by only one pledge', function () {
    $goal = Goal::factory()->create();
    $account = Account::factory()->for($goal->user)->create();
    $transaction = Transaction::factory()->for($account)->create(['user_id' => $goal->user_id]);
    GoalPledge::factory()->for($goal)->for($account)->create(['matched_transaction_id' => $transaction->id]);

    expect(fn () => GoalPledge::factory()->for($goal)->for($account)->create(['matched_transaction_id' => $transaction->id]))
        ->toThrow(QueryException::class);
});

it('rejects linking the same account twice to one goal', function () {
    $goal = Goal::factory()->create();
    $account = Account::factory()->for($goal->user)->create();
    GoalAccount::factory()->for($goal)->for($account)->create();

    expect(fn () => GoalAccount::factory()->for($goal)->for($account)->create())
        ->toThrow(QueryException::class);
});

it('stores goal target and earmarks as integer cents', function () {
    $goal = Goal::factory()->create(['target_amount' => 100000]);
    $link = GoalAccount::factory()->for($goal)->create(['earmark_amount' => 25000]);

    expect($goal->fresh()->target_amount)->toBe(100000)
        ->and($link->fresh()->earmark_amount)->toBe(25000);
});

it('keeps goal migration identifiers within the MariaDB limit', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->andReturn('11.4.5-MariaDB');
    $connection = new MariaDbConnection($pdo, 'identifier_probe', '', ['name' => 'identifier_probe', 'driver' => 'mariadb']);
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());

    try {
        $queries = $connection->pretend(function () {
            (require database_path('migrations/2026_11_14_100000_create_goals_table.php'))->up();
        });
    } finally {
        Schema::swap($originalSchema);
    }

    $identifiers = collect($queries)
        ->flatMap(fn (array $query) => preg_match_all('/`([^`]+)`/', $query['query'], $matches) ? $matches[1] : [])
        ->unique();

    expect($identifiers->filter(fn (string $name) => str_contains($name, 'goal_')))->not->toBeEmpty()
        ->and($identifiers->filter(fn (string $name) => mb_strlen($name) > 64)->values()->all())->toBeEmpty();
});

it('ships with goals disabled by default', function () {
    $key = 'BUDGET_GOALS_ENABLED';
    $snapshot = ['env' => $_ENV[$key] ?? null, 'server' => $_SERVER[$key] ?? null, 'putenv' => getenv($key)];
    $originalConfig = config('budget.goals_enabled');
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);

    try {
        $budgetConfig = require config_path('budget.php');
        config()->set('budget.goals_enabled', $budgetConfig['goals_enabled']);

        expect(config('budget.goals_enabled'))->toBeFalse();
    } finally {
        if ($snapshot['env'] !== null) {
            $_ENV[$key] = $snapshot['env'];
        }

        if ($snapshot['server'] !== null) {
            $_SERVER[$key] = $snapshot['server'];
        }

        if ($snapshot['putenv'] !== false) {
            putenv("{$key}={$snapshot['putenv']}");
        }

        config()->set('budget.goals_enabled', $originalConfig);
    }
});
