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

    'basiq' => [
        'api_key' => env('BASIQ_API_KEY'),
        'base_url' => env('BASIQ_BASE_URL', 'https://au-api.basiq.io'),
        'consent_url' => env('BASIQ_CONSENT_URL', 'https://consent.basiq.io'),
        'webhook_secret' => env('BASIQ_WEBHOOK_SECRET'),
        'seed_user_id' => env('BASIQ_SEED_USER_ID'),
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
