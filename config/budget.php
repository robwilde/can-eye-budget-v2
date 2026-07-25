<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Recurring Transaction Auto-Detection
    |--------------------------------------------------------------------------
    |
    | When enabled, the analysis pipeline scans imported transactions for
    | recurring patterns and surfaces them as suggestions that can be turned
    | into planned transactions. Disabled by default while the core
    | import/reconcile flow is stabilised; it will be rewired into the user
    | rule system before being re-enabled.
    |
    */

    'recurring_detection' => env('BUDGET_RECURRING_DETECTION', false),

    /*
    |--------------------------------------------------------------------------
    | BNPL Email Import
    |--------------------------------------------------------------------------
    |
    | When enabled, a scheduled mailbox scan turns Afterpay (and later Zip,
    | Klarna, PayPal Pay-in-4 and Humm) payment-schedule emails into planned
    | transactions. Disabled until every sub-task of epic #355 has merged:
    | importing schedules without the combined-debit fan-out would make the
    | calendar double-count.
    |
    */

    'bnpl_email_import' => env('BUDGET_BNPL_EMAIL_IMPORT', false),

];
