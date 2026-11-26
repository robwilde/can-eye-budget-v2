<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Services\UserDataResetter;
use Illuminate\Support\Facades\Schema;

const RESET_GLOBAL_TABLES = [
    'migrations',
    'users',
    'cache',
    'cache_locks',
    'jobs',
    'job_batches',
    'failed_jobs',
    'password_reset_tokens',
    'exchange_rates',
    'securities',
    'security_prices',
    'security_splits',
    'instance_settings',
    'invite_codes',
];

/**
 * @return list<string>
 */
function resetUncoveredTables(): array
{
    $tables = array_map(static fn (array $table): string => $table['name'], Schema::getTables());
    $covered = [...UserDataResetter::TABLES, ...UserDataResetter::KEPT_TABLES, ...RESET_GLOBAL_TABLES];

    do {
        $grew = false;

        foreach ($tables as $table) {
            if (in_array($table, $covered, true)) {
                continue;
            }

            foreach (Schema::getForeignKeys($table) as $key) {
                if ($key['on_delete'] === 'cascade' && $key['foreign_table'] !== 'users' && in_array($key['foreign_table'], $covered, true)) {
                    $covered[] = $table;
                    $grew = true;

                    break;
                }
            }
        }
    } while ($grew);

    return array_values(array_diff($tables, $covered));
}

it('lists every table that carries a user_id column as cleared or deliberately kept', function () {
    $listed = [...UserDataResetter::TABLES, ...UserDataResetter::KEPT_TABLES];
    $missing = [];

    foreach (Schema::getTables() as $table) {
        if (Schema::hasColumn($table['name'], 'user_id') && ! in_array($table['name'], $listed, true)) {
            $missing[] = $table['name'];
        }
    }

    expect($missing)->toBe([]);
});

it('reaches every other table through a cascade from a cleared table', function () {
    expect(resetUncoveredTables())->toBe([]);
});

it('only lists tables that exist', function () {
    $tables = array_map(static fn (array $table): string => $table['name'], Schema::getTables());

    expect(array_values(array_diff([...UserDataResetter::TABLES, ...UserDataResetter::KEPT_TABLES], $tables)))->toBe([]);
});
