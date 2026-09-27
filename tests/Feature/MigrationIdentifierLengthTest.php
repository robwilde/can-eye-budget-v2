<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

describe('Migration identifier length validation', function () {
    it('verifies all table foreign key constraints are under MySQL 64-char limit', function () {
        // Get all tables from the database
        $tables = Schema::getTables();

        $exceeding = [];

        foreach ($tables as $tableInfo) {
            $tableName = $tableInfo['name'];

            try {
                $foreignKeys = Schema::getForeignKeys($tableName);

                foreach ($foreignKeys as $fk) {
                    $fkName = $fk['name'] ?? null;
                    if ($fkName && mb_strlen($fkName) > 64) {
                        $exceeding[] = [
                            'table' => $tableName,
                            'constraint_name' => $fkName,
                            'length' => mb_strlen($fkName),
                        ];
                    }
                }
            } catch (Exception) {
                // Some databases or table types may not support getForeignKeys
                // Skip silently for those
                continue;
            }
        }

        if (! empty($exceeding)) {
            $message = 'Found '.count($exceeding)." foreign key constraint(s) exceeding 64 characters:\n";
            foreach ($exceeding as $item) {
                $message .= sprintf(
                    "  - Table: %s, Constraint: %s (length: %d)\n",
                    $item['table'],
                    $item['constraint_name'],
                    $item['length']
                );
            }
            throw new Exception($message);
        }

        expect($exceeding)->toBeEmpty();
    })->group('migrations');

    it('verifies all table indexes are under MySQL 64-char limit', function () {
        // Get all tables from the database
        $tables = Schema::getTables();

        $exceeding = [];

        foreach ($tables as $tableInfo) {
            $tableName = $tableInfo['name'];

            try {
                $indexes = Schema::getIndexes($tableName);

                foreach ($indexes as $index) {
                    $indexName = $index['name'] ?? null;
                    if ($indexName && mb_strlen($indexName) > 64) {
                        $exceeding[] = [
                            'table' => $tableName,
                            'index_name' => $indexName,
                            'length' => mb_strlen($indexName),
                        ];
                    }
                }
            } catch (Exception) {
                // Some databases or table types may not support getIndexes
                // Skip silently for those
                continue;
            }
        }

        if (! empty($exceeding)) {
            $message = 'Found '.count($exceeding)." index(es) exceeding 64 characters:\n";
            foreach ($exceeding as $item) {
                $message .= sprintf(
                    "  - Table: %s, Index: %s (length: %d)\n",
                    $item['table'],
                    $item['index_name'],
                    $item['length']
                );
            }
            throw new Exception($message);
        }

        expect($exceeding)->toBeEmpty();
    })->group('migrations');

});
