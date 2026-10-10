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
    | into planned transactions. Off by default (#580). Import-time detection
    | stays paused until it is rewired into the user-rule system
    | (docs/plans/2026-06-27-recurring-review-rules-page-design.md); users run
    | the on-demand scan on /rules (`RecurringTransactionReview::findRecurring`).
    | `SetupJourneyTest` enables the flag on purpose.
    |
    */

    'recurring_detection' => env('BUDGET_RECURRING_DETECTION', false),

    'jev_categorisation' => env('BUDGET_JEV_CATEGORISATION', false),

    /*
    |--------------------------------------------------------------------------
    | BNPL Email Import
    |--------------------------------------------------------------------------
    |
    | When enabled, a scheduled mailbox scan turns Afterpay (and later Zip,
    | Klarna, PayPal Pay-in-4 and Humm) payment-schedule emails into planned
    | transactions. Off by default; the combined-debit fan-out (#601) is in
    | place, so enabling it is a per-environment choice.
    |
    */

    'bnpl_email_import' => env('BUDGET_BNPL_EMAIL_IMPORT', false),

];
