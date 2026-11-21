<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\StatementReviewStatus;
use App\Models\Account;
use App\Models\Statement;
use App\Models\StatementReconciliation;
use App\Models\User;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

it('rejects a second statement with the same content hash for one user', function () {
    $user = User::factory()->create();
    Statement::factory()->for($user)->create(['content_hash' => str_repeat('a', 64)]);

    expect(fn () => Statement::factory()->for($user)->create(['content_hash' => str_repeat('a', 64)]))
        ->toThrow(QueryException::class);
});

it('allows the same content hash for different users', function () {
    Statement::factory()->create(['content_hash' => str_repeat('b', 64)]);
    Statement::factory()->create(['content_hash' => str_repeat('b', 64)]);

    expect(Statement::query()->where('content_hash', str_repeat('b', 64))->count())->toBe(2);
});

it('derives review status from the linked account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $statement = Statement::factory()->for($user)->unmatched()->create();

    $statement->update(['account_id' => $account->id]);
    $linked = $statement->fresh()->review_status;

    $statement->update(['account_id' => null]);
    $unlinked = $statement->fresh()->review_status;

    $rejected = Statement::factory()->for($user)->rejected()->create();
    $rejected->update(['institution_hint' => 'Example Bank']);

    expect($linked)->toBe(StatementReviewStatus::Linked)
        ->and($unlinked)->toBe(StatementReviewStatus::Unmatched)
        ->and($rejected->fresh()->review_status)->toBe(StatementReviewStatus::Rejected);
});

it('links a rejected statement once it gets an account', function () {
    $user = User::factory()->create();
    $statement = Statement::factory()->for($user)->rejected()->create();

    $statement->update(['account_id' => Account::factory()->for($user)->create()->id]);

    expect($statement->fresh()->review_status)->toBe(StatementReviewStatus::Linked);
});

it('purges the stored file when a statement is deleted', function () {
    Storage::fake('local');
    Storage::disk('local')->put('statements/one.pdf', '%PDF-1.4');
    Storage::disk('local')->put('statements/two.pdf', '%PDF-1.4');
    $statement = Statement::factory()->create(['stored_path' => 'statements/one.pdf']);

    $statement->delete();

    expect(Storage::disk('local')->exists('statements/one.pdf'))->toBeFalse()
        ->and(Storage::disk('local')->exists('statements/two.pdf'))->toBeTrue();
});

it('purges every stored statement file when the user is deleted', function () {
    Storage::fake('local');
    Storage::disk('local')->put('statements/mine.pdf', '%PDF-1.4');
    Storage::disk('local')->put('statements/theirs.pdf', '%PDF-1.4');
    $user = User::factory()->create();
    Statement::factory()->for($user)->create(['stored_path' => 'statements/mine.pdf']);
    Statement::factory()->create(['stored_path' => 'statements/theirs.pdf']);

    $user->delete();

    expect(Storage::disk('local')->exists('statements/mine.pdf'))->toBeFalse()
        ->and(Storage::disk('local')->exists('statements/theirs.pdf'))->toBeTrue()
        ->and(Statement::query()->count())->toBe(1);
});

it('keeps the stored file when a statement delete rolls back', function () {
    Storage::fake('local');
    Storage::disk('local')->put('statements/kept.pdf', '%PDF-1.4');
    $statement = Statement::factory()->create(['stored_path' => 'statements/kept.pdf']);

    try {
        DB::transaction(function () use ($statement) {
            $statement->delete();

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect(Statement::query()->whereKey($statement->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists('statements/kept.pdf'))->toBeTrue();
});

it('keeps the stored files when a user delete rolls back', function () {
    Storage::fake('local');
    Storage::disk('local')->put('statements/kept.pdf', '%PDF-1.4');
    $user = User::factory()->create();
    $statement = Statement::factory()->for($user)->create(['stored_path' => 'statements/kept.pdf']);

    try {
        DB::transaction(function () use ($user) {
            $user->delete();

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect(Statement::query()->whereKey($statement->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists('statements/kept.pdf'))->toBeTrue();
});

it('stores opening and closing balances as integer cents', function () {
    $statement = Statement::factory()->create(['opening_balance' => 123456, 'closing_balance' => -2000]);

    $stored = DB::table('statements')->where('id', $statement->id)->first();

    expect($stored->opening_balance)->toBe(123456)
        ->and($stored->closing_balance)->toBe(-2000)
        ->and($statement->fresh()->opening_balance)->toBe(123456)
        ->and($statement->fresh()->closing_balance)->toBe(-2000);
});

it('unmatches a statement and clears its suggestion confidence when its account is deleted', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $other = Account::factory()->for($user)->create();
    $bystander = Statement::factory()->for($user)->create([
        'account_id' => $other->id,
        'suggested_account_id' => $other->id,
        'suggestion_confidence' => '0.9000',
    ]);
    $linked = Statement::factory()->for($user)->create(['account_id' => $account->id]);
    $suggested = Statement::factory()->for($user)->create([
        'suggested_account_id' => $account->id,
        'suggestion_confidence' => '0.8000',
    ]);

    $account->delete();

    expect($linked->fresh()->account_id)->toBeNull()
        ->and($linked->fresh()->review_status)->toBe(StatementReviewStatus::Unmatched)
        ->and($suggested->fresh()->suggested_account_id)->toBeNull()
        ->and($suggested->fresh()->suggestion_confidence)->toBeNull()
        ->and($bystander->fresh()->review_status)->toBe(StatementReviewStatus::Linked)
        ->and($bystander->fresh()->suggestion_confidence)->toBe('0.9000');
});

it('lets a reconciliation point at its statement and survive the statement being deleted', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $statement = Statement::factory()->for($user)->create(['account_id' => $account->id]);
    $reconciliation = StatementReconciliation::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'statement_id' => $statement->id,
    ]);

    expect($reconciliation->statement->is($statement))->toBeTrue()
        ->and($statement->reconciliations->modelKeys())->toBe([$reconciliation->id]);

    $statement->delete();

    expect($reconciliation->fresh()->statement_id)->toBeNull();
});

it('scopes statements to their owner', function () {
    $owner = User::factory()->create();
    $mine = Statement::factory()->for($owner)->create();
    Statement::factory()->create();

    expect($owner->statements()->pluck('id')->all())->toBe([$mine->id])
        ->and(Statement::query()->forUser($owner)->pluck('id')->all())->toBe([$mine->id]);
});

it('keeps statement index names within the MariaDB identifier limit', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->andReturn('11.4.5-MariaDB');
    $connection = new MariaDbConnection($pdo, 'identifier_probe', '', ['name' => 'identifier_probe', 'driver' => 'mariadb']);
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());

    try {
        $queries = $connection->pretend(function () {
            (require database_path('migrations/2026_11_21_400000_create_statements_table.php'))->up();
        });
    } finally {
        Schema::swap($originalSchema);
    }

    $identifiers = collect($queries)
        ->flatMap(fn (array $query) => preg_match_all('/`([^`]+)`/', $query['query'], $matches) ? $matches[1] : [])
        ->unique();

    expect($identifiers->filter(fn (string $name) => str_contains($name, 'statements_')))->not->toBeEmpty()
        ->and($identifiers->contains('statement_reconciliations_statement_id_foreign'))->toBeTrue()
        ->and($identifiers->filter(fn (string $name) => mb_strlen($name) > 64)->values()->all())->toBeEmpty();
});

it('rolls the statements migration back and forward again', function () {
    $migration = require database_path('migrations/2026_11_21_400000_create_statements_table.php');

    $migration->down();

    $rolledBack = Schema::hasTable('statements') || Schema::hasColumn('statement_reconciliations', 'statement_id');

    $migration->up();

    expect($rolledBack)->toBeFalse()
        ->and(Schema::hasTable('statements'))->toBeTrue()
        ->and(Schema::hasColumn('statement_reconciliations', 'statement_id'))->toBeTrue();
});
