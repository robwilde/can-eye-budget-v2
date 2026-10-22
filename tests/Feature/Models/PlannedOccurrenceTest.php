<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\User;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

test('allows only one occurrence per plan and original date', function () {
    $plan = PlannedTransaction::factory()->create();
    PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-15', 'due_date' => '2026-03-15']);

    expect(fn () => PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-03-15', 'due_date' => '2026-03-16']))
        ->toThrow(QueryException::class);

    PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => '2026-04-15', 'due_date' => '2026-04-15']);
    expect($plan->occurrences()->count())->toBe(2);
});

test('deletes occurrences with their plan', function () {
    $plan = PlannedTransaction::factory()->create();
    PlannedOccurrence::factory()->count(2)->for($plan, 'plannedTransaction')->sequence(
        ['original_date' => '2026-03-15', 'due_date' => '2026-03-15'],
        ['original_date' => '2026-04-15', 'due_date' => '2026-04-15'],
    )->create();

    $plan->delete();

    expect(PlannedOccurrence::query()->count())->toBe(0);
});

test('scopes occurrences to the owning user through the plan', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $own = PlannedOccurrence::factory()->for(PlannedTransaction::factory()->for($mine)->create(), 'plannedTransaction')->create();
    PlannedOccurrence::factory()->for(PlannedTransaction::factory()->for($theirs)->create(), 'plannedTransaction')->create();

    expect(PlannedOccurrence::query()->forUser($mine)->pluck('id')->all())->toBe([$own->id])
        ->and(PlannedOccurrence::query()->forUser($mine->id)->count())->toBe(1);
});

test('falls back to the plan amount when no expected amount is stored', function () {
    $plan = PlannedTransaction::factory()->create(['amount' => 10000]);
    $inherits = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['expected_amount' => null]);
    $overrides = PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['expected_amount' => 12000, 'original_date' => '2026-05-01', 'due_date' => '2026-05-01']);

    expect($inherits->effectiveExpectedAmount())->toBe(10000)
        ->and($overrides->effectiveExpectedAmount())->toBe(12000);
});

test('keeps occurrence migration identifiers within the MariaDB limit', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->andReturn('11.4.5-MariaDB');
    $connection = new MariaDbConnection($pdo, 'identifier_probe', '', ['name' => 'identifier_probe', 'driver' => 'mariadb']);
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());

    try {
        $queries = $connection->pretend(function () {
            (require database_path('migrations/2026_10_22_100000_create_planned_occurrences_table.php'))->up();
        });
    } finally {
        Schema::swap($originalSchema);
    }

    $identifiers = collect($queries)
        ->flatMap(fn (array $query) => preg_match_all('/`([^`]+)`/', $query['query'], $matches) ? $matches[1] : [])
        ->unique();

    expect($identifiers->filter(fn (string $name) => str_contains($name, 'planned_occurrences_')))->not->toBeEmpty()
        ->and($identifiers->filter(fn (string $name) => mb_strlen($name) > 64)->values()->all())->toBeEmpty();
});

test('uses the snoozed date as the effective due date and stores a status', function () {
    $occurrence = PlannedOccurrence::factory()->snoozed()->missed()->create(['due_date' => '2026-03-15']);

    expect($occurrence->fresh()->status)->toBe(OccurrenceStatus::Missed)
        ->and($occurrence->effectiveDueDate()->toDateString())->toBe($occurrence->snoozed_until->toDateString())
        ->and(PlannedOccurrence::factory()->create(['due_date' => '2026-03-15'])->effectiveDueDate()->toDateString())->toBe('2026-03-15');
});

test('starts a new occurrence as scheduled and not manually closed', function () {
    $plan = PlannedTransaction::factory()->create();

    $created = PlannedOccurrence::create(['planned_transaction_id' => $plan->id, 'original_date' => '2026-03-15', 'due_date' => '2026-03-15']);
    $fromFactory = PlannedOccurrence::factory()->create();

    expect($created->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($created->closed_manually)->toBeFalse()
        ->and($fromFactory->closed_manually)->toBeFalse();
});
