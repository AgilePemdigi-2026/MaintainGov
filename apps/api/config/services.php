<?php

return [
    'analysis' => [
        'url' => env('ANALYSIS_SERVICE_URL', 'http://127.0.0.1:8001'),
        'timeout' => (int) env('ANALYSIS_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub integration (server-side, read-only connection check)
    |--------------------------------------------------------------------------
    |
    | The token is only read by Laravel. It must never be sent to the browser,
    | committed to the repository, or included in API responses/logs. GitHub
    | App JWT exchange is intentionally not implemented until the team has
    | verified App credentials and permissions (see docs/GITHUB_INTEGRATION.md).
    |
    */
    'github' => [
        'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),
        'owner' => env('GITHUB_REPOSITORY_OWNER', 'AgilePemdigi-2026'),
        'repository' => env('GITHUB_REPOSITORY_NAME', 'MaintainGov'),
        'token' => env('GITHUB_TOKEN'),
        'timeout' => (int) env('GITHUB_TIMEOUT', 10),
        'connect_timeout' => (int) env('GITHUB_CONNECT_TIMEOUT', 5),
        'default_branch' => env('GITHUB_DEFAULT_BRANCH', 'main'),
        // HMAC secret used by POST /api/v1/webhooks/github. Empty disables
        // webhook processing (requests are rejected, never accepted unsigned).
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
        'webhook_max_age' => (int) env('GITHUB_WEBHOOK_MAX_AGE', 900),
        // A pending PR row younger than this is treated as an in-flight
        // submission; older pending rows may be reconciled/retried safely.
        'pull_request_stale_seconds' => (int) env('GITHUB_PR_STALE_SECONDS', 60),
        // Mock UC04-UC05 routes are hidden outside local/testing by default.
        'mock_enabled' => filter_var(
            env('GITHUB_MOCK_ENABLED', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),
            FILTER_VALIDATE_BOOL,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

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
