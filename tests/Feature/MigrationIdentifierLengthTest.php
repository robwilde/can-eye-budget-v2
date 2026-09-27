<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use Illuminate\Database\MariaDbConnection;
use Illuminate\Support\Facades\Schema;

/*
 * The test suite runs on SQLite, which neither enforces the 64-character identifier
 * limit nor reports foreign-key constraint names. Compiling every migration with the
 * MariaDB grammar in pretend mode yields the exact DDL staging executes, so each
 * backtick-quoted identifier (table, column, index, constraint) can be measured
 * without a MariaDB server.
 */
it('keeps every identifier in the MariaDB DDL within the 64-character limit', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->andReturn('11.4.5-MariaDB');

    $connection = new MariaDbConnection($pdo, 'identifier_probe', '', ['name' => 'identifier_probe', 'driver' => 'mariadb']);
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());

    try {
        $queries = $connection->pretend(function () {
            foreach (glob(database_path('migrations/*.php')) as $path) {
                (require $path)->up();
            }
        });
    } finally {
        Schema::swap($originalSchema);
    }

    $exceeding = collect($queries)
        ->flatMap(fn (array $query) => preg_match_all('/`([^`]+)`/', $query['query'], $matches) ? $matches[1] : [])
        ->filter(fn (string $identifier) => mb_strlen($identifier) > 64)
        ->unique()
        ->values()
        ->all();

    expect($queries)->not->toBeEmpty()
        ->and($exceeding)->toBeEmpty();
})->group('migrations');
