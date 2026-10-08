<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

test('recurring detection is disabled by the config fallback', function () {
    $key = 'BUDGET_RECURRING_DETECTION';
    $snapshot = ['env' => $_ENV[$key] ?? null, 'server' => $_SERVER[$key] ?? null, 'putenv' => getenv($key)];
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);

    try {
        $budgetConfig = require config_path('budget.php');

        expect($budgetConfig['recurring_detection'])->toBeFalse();
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
    }
});
