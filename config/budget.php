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

    /*
    |--------------------------------------------------------------------------
    | Cross-Currency Transfer Tolerance
    |--------------------------------------------------------------------------
    |
    | How far, in percent, the incoming amount of a cross-currency transfer may
    | differ from the outgoing amount converted at the day's rate and still be
    | matched as one transfer. Values outside 0 to 50 are clamped in code
    | (TransferExchange::tolerancePercent).
    |
    */

    'transfer_fx_tolerance' => env('BUDGET_TRANSFER_FX_TOLERANCE', 10),

    /*
    |--------------------------------------------------------------------------
    | Multi-Currency Totals
    |--------------------------------------------------------------------------
    |
    | When enabled, totals, reports and the balance sheet convert into the
    | user's base currency (CurrencyConverter): transaction totals at each
    | entry's date, account balances at today's rate, and the net-worth series
    | at each day's rate. A missing rate counts as 1. Off by default; with it
    | off every total adds cents unconverted.
    |
    */

    'multi_currency' => (bool) env('BUDGET_MULTI_CURRENCY', false),

    /*
    |--------------------------------------------------------------------------
    | Savings Goals
    |--------------------------------------------------------------------------
    |
    | When enabled, the goals feature (goals, linked accounts and pledges) is
    | available. Off by default.
    |
    */

    'goals_enabled' => (bool) env('BUDGET_GOALS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Insights Feed
    |--------------------------------------------------------------------------
    |
    | When enabled, the insights feature (typed, prioritised observations with a
    | read, acknowledge and resurfacing lifecycle) is available. Off by default.
    |
    */

    'insights_enabled' => (bool) env('BUDGET_INSIGHTS_ENABLED', false),

];
