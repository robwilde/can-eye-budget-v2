<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

test('bnpl email import is disabled by the config fallback', function () {
    $key = 'BUDGET_BNPL_EMAIL_IMPORT';
    $snapshot = ['env' => $_ENV[$key] ?? null, 'server' => $_SERVER[$key] ?? null, 'putenv' => getenv($key)];
    $originalConfig = config('budget.bnpl_email_import');
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);

    try {
        $budgetConfig = require config_path('budget.php');
        config()->set('budget.bnpl_email_import', $budgetConfig['bnpl_email_import']);

        expect(config('budget.bnpl_email_import'))->toBeFalse();
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

        config()->set('budget.bnpl_email_import', $originalConfig);
    }
});
