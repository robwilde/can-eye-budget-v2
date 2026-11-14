<?php

declare(strict_types=1);

use App\Enums\InsightPriority;
use App\Enums\InsightStatus;
use App\Enums\InsightType;
use App\Models\Account;
use App\Models\Insight;
use App\Models\User;
use App\Services\InsightRecorder;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Support\Facades\Schema;

function recordInsight(User $user, string $key, array $comparison, string $title = 'Idle cash', InsightType $type = InsightType::IdleCash): Insight
{
    return resolve(InsightRecorder::class)->record(
        user: $user,
        type: $type,
        priority: InsightPriority::Medium,
        dedupKey: $key,
        title: $title,
        body: "Body for {$title}",
        figures: ['amount' => $comparison['amount'] ?? 0],
        comparison: $comparison,
    );
}

it('resurfaces an acknowledged insight only when its comparison values change', function () {
    $user = User::factory()->create();
    $recorder = resolve(InsightRecorder::class);
    $insight = recordInsight($user, 'idle_cash:1', ['amount' => 500000]);
    $recorder->acknowledge($insight);

    $same = recordInsight($user, 'idle_cash:1', ['amount' => 500000], 'Refreshed title');

    expect($same->is($insight))->toBeTrue()
        ->and($same->fresh()->status)->toBe(InsightStatus::Acknowledged)
        ->and($same->fresh()->title)->toBe('Refreshed title')
        ->and($same->fresh()->body)->toBe('Body for Idle cash');

    $changed = recordInsight($user, 'idle_cash:1', ['amount' => 600000], 'New title');

    expect($changed->fresh()->status)->toBe(InsightStatus::Active)
        ->and($changed->fresh()->acknowledged_at)->toBeNull()
        ->and($changed->fresh()->body)->toBe('Body for New title')
        ->and(Insight::query()->count())->toBe(1);
});

it('expires insights only for types whose check succeeded', function () {
    $user = User::factory()->create();
    $idle = Insight::factory()->for($user)->create(['type' => InsightType::IdleCash, 'dedup_key' => 'idle_cash:1']);
    $savings = Insight::factory()->for($user)->create(['type' => InsightType::SavingsRateChange, 'dedup_key' => 'savings_rate:2026-10']);
    $kept = Insight::factory()->for($user)->create(['type' => InsightType::IdleCash, 'dedup_key' => 'idle_cash:2']);

    $count = resolve(InsightRecorder::class)->expireMissing($user, [InsightType::IdleCash], ['idle_cash:2']);

    expect($count)->toBe(1)
        ->and($idle->fresh()->status)->toBe(InsightStatus::Expired)
        ->and($idle->fresh()->expired_at)->not->toBeNull()
        ->and($savings->fresh()->status)->toBe(InsightStatus::Active)
        ->and($kept->fresh()->status)->toBe(InsightStatus::Active);

    $returned = recordInsight($user, 'idle_cash:1', []);

    expect($returned->fresh()->status)->toBe(InsightStatus::Active)
        ->and($returned->fresh()->expired_at)->toBeNull();
});

it('restores an undone acknowledgement as read', function () {
    $recorder = resolve(InsightRecorder::class);
    $acknowledged = Insight::factory()->acknowledged()->create();
    $expired = Insight::factory()->expired()->create();

    $recorder->undoAcknowledge($acknowledged);
    $recorder->undoAcknowledge($expired);

    expect($acknowledged->fresh()->status)->toBe(InsightStatus::Read)
        ->and($acknowledged->fresh()->acknowledged_at)->toBeNull()
        ->and($expired->fresh()->status)->toBe(InsightStatus::Expired);
});

it('ignores acknowledgement and undo on a stale copy of an expired insight', function () {
    $recorder = resolve(InsightRecorder::class);
    $user = User::factory()->create();
    $stale = Insight::factory()->for($user)->create(['type' => InsightType::IdleCash, 'dedup_key' => 'idle_cash:1']);
    $recorder->expireMissing($user, [InsightType::IdleCash], []);

    $recorder->acknowledge($stale);

    expect($stale->fresh()->status)->toBe(InsightStatus::Expired)
        ->and($stale->status)->toBe(InsightStatus::Expired)
        ->and($stale->fresh()->acknowledged_at)->toBeNull();

    $acknowledged = Insight::factory()->for($user)->acknowledged()->create(['type' => InsightType::IdleCash, 'dedup_key' => 'idle_cash:2']);
    $copy = Insight::query()->findOrFail($acknowledged->id);
    $recorder->expireMissing($user, [InsightType::IdleCash], []);

    $recorder->undoAcknowledge($copy);

    expect($acknowledged->fresh()->status)->toBe(InsightStatus::Expired)
        ->and($copy->status)->toBe(InsightStatus::Expired);
});

it('marks only the users active insights as read', function () {
    $user = User::factory()->create();
    $active = Insight::factory()->for($user)->create();
    $acknowledged = Insight::factory()->for($user)->acknowledged()->create();
    $other = Insight::factory()->create();

    $count = resolve(InsightRecorder::class)->markAllRead($user);

    expect($count)->toBe(1)
        ->and($active->fresh()->status)->toBe(InsightStatus::Read)
        ->and($active->fresh()->read_at)->not->toBeNull()
        ->and($acknowledged->fresh()->status)->toBe(InsightStatus::Acknowledged)
        ->and($other->fresh()->status)->toBe(InsightStatus::Active);
});

it('orders insights by priority then newest first', function () {
    $user = User::factory()->create();
    $low = Insight::factory()->for($user)->create(['priority' => InsightPriority::Low, 'created_at' => now()->subHour()]);
    $olderHigh = Insight::factory()->for($user)->create(['priority' => InsightPriority::High, 'created_at' => now()->subDays(2)]);
    $medium = Insight::factory()->for($user)->create(['priority' => InsightPriority::Medium, 'created_at' => now()->subDay()]);
    $newerHigh = Insight::factory()->for($user)->create(['priority' => InsightPriority::High, 'created_at' => now()->subDay()]);

    expect(Insight::query()->where('user_id', $user->id)->feedOrder()->pluck('id')->all())
        ->toBe([$newerHigh->id, $olderHigh->id, $medium->id, $low->id]);
});

it('keeps insight dedup keys unique per user only', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $one = recordInsight($first, 'idle_cash:1', ['amount' => 100]);
    $two = recordInsight($second, 'idle_cash:1', ['amount' => 200]);
    resolve(InsightRecorder::class)->acknowledge($one);
    recordInsight($second, 'idle_cash:1', ['amount' => 300]);

    expect(Insight::query()->count())->toBe(2)
        ->and($one->fresh()->status)->toBe(InsightStatus::Acknowledged)
        ->and($one->fresh()->comparison)->toBe(['amount' => 100])
        ->and($two->fresh()->comparison)->toBe(['amount' => 300]);
});

it('drops insights for a deleted account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Insight::factory()->for($user)->create(['account_id' => $account->id]);
    $unrelated = Insight::factory()->for($user)->create();

    $account->delete();

    expect(Insight::query()->where('user_id', $user->id)->feedOrder()->pluck('id')->all())->toBe([$unrelated->id]);
});

it('refuses an account owned by a different user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $account = Account::factory()->for($other)->create();

    expect(fn () => resolve(InsightRecorder::class)->record(
        user: $user,
        type: InsightType::IdleCash,
        priority: InsightPriority::Low,
        dedupKey: 'idle_cash:x',
        title: 'Idle',
        body: 'Idle',
        figures: [],
        comparison: [],
        account: $account,
    ))->toThrow(InvalidArgumentException::class);

    $account->delete();

    expect(Insight::query()->count())->toBe(0);
});

it('bounds long titles to the column size', function () {
    $user = User::factory()->create();

    $insight = recordInsight($user, 'long:1', ['amount' => 1], str_repeat('é', 400));

    expect(mb_strlen($insight->fresh()->title))->toBe(255)
        ->and($insight->fresh()->title)->toEndWith('…');
});

it('locks the user row before looking up a dedup key', function () {
    $user = User::factory()->create();
    $queries = [];
    Insight::resolveConnection()->listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    recordInsight($user, 'lock:1', ['amount' => 1]);

    $position = fn (string $table): int|false => collect($queries)->search(fn (string $sql): bool => preg_match('/from [`"]'.$table.'[`"]/', $sql) === 1);

    expect($position('users'))->not->toBeFalse()
        ->and($position('users'))->toBeLessThan($position('insights'));
});

it('refuses to acknowledge an instance whose owner does not match the stored row', function () {
    $owner = User::factory()->create();
    $insight = Insight::factory()->for($owner)->create();
    $forged = Insight::query()->findOrFail($insight->id);
    $forged->user_id = User::factory()->create()->id;

    expect(fn () => resolve(InsightRecorder::class)->acknowledge($forged))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and(fn () => resolve(InsightRecorder::class)->undoAcknowledge($forged))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and($insight->fresh()->status)->toBe(InsightStatus::Active);
});

it('keeps insight migration identifiers within the MariaDB limit', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->andReturn('11.4.5-MariaDB');
    $connection = new MariaDbConnection($pdo, 'identifier_probe', '', ['name' => 'identifier_probe', 'driver' => 'mariadb']);
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());

    try {
        $queries = $connection->pretend(function () {
            (require database_path('migrations/2026_11_14_100100_create_insights_table.php'))->up();
        });
    } finally {
        Schema::swap($originalSchema);
    }

    $identifiers = collect($queries)
        ->flatMap(fn (array $query) => preg_match_all('/`([^`]+)`/', $query['query'], $matches) ? $matches[1] : [])
        ->unique();

    expect($identifiers->filter(fn (string $name) => str_contains($name, 'insights_')))->not->toBeEmpty()
        ->and($identifiers->filter(fn (string $name) => mb_strlen($name) > 64)->values()->all())->toBeEmpty();
});

it('ships with insights disabled by default', function () {
    $key = 'BUDGET_INSIGHTS_ENABLED';
    $snapshot = ['env' => $_ENV[$key] ?? null, 'server' => $_SERVER[$key] ?? null, 'putenv' => getenv($key)];
    $originalConfig = config('budget.insights_enabled');
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);

    try {
        $budgetConfig = require config_path('budget.php');
        config()->set('budget.insights_enabled', $budgetConfig['insights_enabled']);

        expect(config('budget.insights_enabled'))->toBeFalse();
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

        config()->set('budget.insights_enabled', $originalConfig);
    }
});
