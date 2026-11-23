<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'github' => [
        'token' => env('GITHUB_TOKEN'),
        'feedback_repo' => env('GITHUB_FEEDBACK_REPO', 'robwilde/can-eye-budget-v2'),
        'feedback_release_id' => env('GITHUB_FEEDBACK_RELEASE_ID'),
    ],

    'feedback' => [
        'screenshot_url' => env('FEEDBACK_SCREENSHOT_URL'),
    ],

    'context_dev' => [
        // Server-side secret. Passed to the SDK explicitly (not left to its getenv()
        // fallback) so the value survives config:cache in the container entrypoint.
        'api_key' => env('CONTEXT_DEV_API_KEY'),
        // Automatic transaction enrichment (#465). Off unless opted in: every lookup
        // costs 10 credits. The cap bounds automatic and user-requested lookups per
        // Australia/Brisbane day.
        'enrichment_enabled' => (bool) env('CONTEXT_DEV_ENRICHMENT_ENABLED', false),
        'daily_credit_cap' => (int) env('CONTEXT_DEV_DAILY_CREDIT_CAP', 500),
    ],

    'statement_ai' => [
        // Provider for reading uploaded statement PDFs (Anthropic Messages API). The full PDF
        // bytes are sent to this provider, and only while budget.pdf_import.enabled is on and
        // a key is set.
        'api_key' => env('STATEMENT_AI_API_KEY'),
        'base_url' => env('STATEMENT_AI_BASE_URL') ?: 'https://api.anthropic.com/v1',
        'model' => env('STATEMENT_AI_MODEL') ?: 'claude-sonnet-4-5',
        'max_pdf_bytes' => (int) (env('STATEMENT_AI_MAX_PDF_BYTES') ?: 20 * 1024 * 1024),
        'max_tokens' => (int) (env('STATEMENT_AI_MAX_TOKENS') ?: 16000),
    ],

    'vector_store' => [
        // Hosted document store (OpenAI vector stores). User documents and analysed statement
        // PDFs are sent to it only when budget.documents.vector_store is 'openai' and a key is set.
        'openai' => [
            'api_key' => env('OPENAI_VECTOR_STORE_API_KEY'),
            'base_url' => env('OPENAI_VECTOR_STORE_BASE_URL') ?: 'https://api.openai.com/v1',
            'timeout' => (int) (env('OPENAI_VECTOR_STORE_TIMEOUT') ?: 60),
            'connect_timeout' => (int) (env('OPENAI_VECTOR_STORE_CONNECT_TIMEOUT') ?: 10),
        ],
    ],

    'typesafe' => [
        // Server-side secret for TypeSafe System One (Jev). Only the offline
        // categories:jev-eval command (#505) calls it; nothing in the request path does.
        'api_key' => env('TYPESAFE_API_KEY'),
        'base_url' => env('TYPESAFE_BASE_URL') ?: 'https://api.typesafe.ai',
        'model' => env('TYPESAFE_MODEL') ?: 'jev-latest',
        // Seconds one request may take when a person is waiting on it (the plan smart fill); it is
        // never retried, so a slow or failing call gives way to the plain form quickly.
        'interactive_timeout' => (int) env('TYPESAFE_INTERACTIVE_TIMEOUT', 10),
    ],

    'exchange_rates' => [
        // Market-data provider for the exchange-rates:fetch command (#711). Off unless opted in;
        // while off no request is made and the app keeps using cached rates. The client speaks
        // the Frankfurter API shape: GET {base_url}/{date}?base=USD&symbols=EUR,AUD.
        'enabled' => (bool) env('EXCHANGE_RATES_ENABLED', false),
        'base_url' => env('EXCHANGE_RATES_BASE_URL') ?: 'https://api.frankfurter.dev/v1',
        'api_key' => env('EXCHANGE_RATES_API_KEY'),
    ],

    'security_prices' => [
        // Price and ticker-search provider for investments (#701). Off unless opted in; while off no
        // request is made and the stored prices are kept. The client speaks the Twelve Data API:
        // GET {base_url}/time_series and /symbol_search, with the key in the Authorization header.
        'enabled' => (bool) env('SECURITY_PRICES_ENABLED', false),
        'base_url' => env('SECURITY_PRICES_BASE_URL') ?: 'https://api.twelvedata.com',
        'api_key' => env('SECURITY_PRICES_API_KEY'),
    ],

    'redbark' => [
        'base_url' => env('REDBARK_BASE_URL') ?: 'https://api.redbark.com/v1',
        'include_pending' => (bool) env('REDBARK_INCLUDE_PENDING', false),
        'hold_ttl_days' => (int) env('REDBARK_HOLD_TTL_DAYS', 14),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
