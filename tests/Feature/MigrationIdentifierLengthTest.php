<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('keeps every index name within the MySQL 64-character identifier limit', function () {
    $exceeding = [];

    foreach (Schema::getTables() as $table) {
        foreach (Schema::getIndexes($table['name']) as $index) {
            if (mb_strlen($index['name']) > 64) {
                $exceeding[] = $table['name'].'.'.$index['name'];
            }
        }
    }

    expect($exceeding)->toBeEmpty();
})->group('migrations');
