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

];
