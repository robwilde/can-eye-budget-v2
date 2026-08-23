<?php

declare(strict_types=1);

use Illuminate\Support\Env;

test('bnpl email import is disabled by the config fallback', function () {
    $repository = Env::getRepository();
    $originalEnvironment = $repository->get('BUDGET_BNPL_EMAIL_IMPORT');
    $originalConfig = config('budget.bnpl_email_import');
    $repository->clear('BUDGET_BNPL_EMAIL_IMPORT');

    try {
        $budgetConfig = require config_path('budget.php');
        config()->set('budget.bnpl_email_import', $budgetConfig['bnpl_email_import']);

        expect(config('budget.bnpl_email_import'))->toBeFalse();
    } finally {
        if ($originalEnvironment !== null) {
            $repository->set('BUDGET_BNPL_EMAIL_IMPORT', (string) $originalEnvironment);
        }

        config()->set('budget.bnpl_email_import', $originalConfig);
    }
});
