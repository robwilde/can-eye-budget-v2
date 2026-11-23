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
    | Bank-statement PDF Import
    |--------------------------------------------------------------------------
    |
    | When enabled, and services.statement_ai.api_key is set, an uploaded statement
    | PDF can be sent to the configured AI provider to extract its transactions
    | for review. The whole PDF leaves the app. Off by default.
    |
    */

    'pdf_import' => [
        'enabled' => (bool) env('BUDGET_PDF_IMPORT_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Categorise Rule Action
    |--------------------------------------------------------------------------
    |
    | When enabled, and services.typesafe.api_key is set, rules can use the
    | "Categorise with AI" action. It only queues a background job; previews and
    | in-request applies never call TypeSafe. Off by default.
    |
    */

    'rule_ai_categorisation' => (bool) env('BUDGET_RULE_AI_CATEGORISATION', false),

    /*
    |--------------------------------------------------------------------------
    | Plan Smart Fill
    |--------------------------------------------------------------------------
    |
    | When enabled, and services.typesafe.api_key is set, the add-bill form can
    | propose a plan's name, amount, cadence, due day, category, type and autopay
    | from the user's past charges, and the plans page can list the fields a plan
    | contradicts. A person waits on each call, so it is bounded by
    | services.typesafe.interactive_timeout and never blocks the plain form. Off by default.
    |
    */

    'plan_smart_fill' => (bool) env('BUDGET_PLAN_SMART_FILL', false),

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
    | Investments
    |--------------------------------------------------------------------------
    |
    | When enabled, the investments feature (securities, prices, holdings and
    | trades on investment accounts) is available. Off by default.
    |
    */

    'investments' => [
        'enabled' => (bool) env('BUDGET_INVESTMENTS_ENABLED', false),
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Feed Category Matching
    |--------------------------------------------------------------------------
    |
    | When enabled, the category a Redbark feed supplies is matched onto the
    | account owner's existing categories (FeedCategoryMatcher) for accounts
    | whose category matcher is on, and the matcher switch shows in the
    | Redbark account setup. Nothing is ever created and an existing category
    | is never overwritten. Off by default.
    |
    */

    'feed_category_matching' => (bool) env('BUDGET_FEED_CATEGORY_MATCHING', false),

    /*
    |--------------------------------------------------------------------------
    | Redbark Nightly Sync
    |--------------------------------------------------------------------------
    |
    | A sync of every healthy feed at this time (HH:MM) in the given timezone, on
    | top of the six-hourly one. A run that is already open is joined, never
    | doubled. Set the time to null (BUDGET_REDBARK_NIGHTLY_SYNC_TIME=null) to
    | switch it off.
    |
    */

    'redbark' => [
        'nightly_sync_time' => env('BUDGET_REDBARK_NIGHTLY_SYNC_TIME', '02:22'),
        'nightly_sync_timezone' => env('BUDGET_REDBARK_NIGHTLY_SYNC_TIMEZONE', 'UTC'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    |
    | Documents uploaded from the import screen are kept for later indexing. They
    | are refused while no vector store driver is named. accepted_types lists the
    | file extensions each driver takes (PDFs go to the PDF import instead).
    |
    */

    'documents' => [
        'vector_store' => env('BUDGET_DOCUMENTS_VECTOR_STORE'),
        'accepted_types' => [
            'openai' => ['txt', 'md', 'json', 'html', 'doc', 'docx', 'pptx'],
        ],
    ],

];
