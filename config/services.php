<?php

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

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
        'intake_model' => env('GEMINI_INTAKE_MODEL', env('GEMINI_MODEL', 'gemini-2.0-flash')),
        'intake_fallback_model' => env('GEMINI_INTAKE_FALLBACK_MODEL'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'verify_ssl' => env('GEMINI_VERIFY_SSL', true),
        'intake_max_attempts' => (int) env('GEMINI_INTAKE_MAX_ATTEMPTS', 2),
        'intake_retry_delay_seconds' => (int) env('GEMINI_INTAKE_RETRY_DELAY_SECONDS', 15),
        'intake_retry_jitter_seconds' => (int) env('GEMINI_INTAKE_RETRY_JITTER_SECONDS', 4),
        'intake_http_retry_delays_ms' => array_values(array_filter(array_map(
            static fn ($value) => (int) trim($value),
            explode(',', (string) env('GEMINI_INTAKE_HTTP_RETRY_DELAYS_MS', '1000,3000')),
        ), static fn ($value) => $value >= 0)),
        'intake_global_concurrency' => (int) env('GEMINI_INTAKE_GLOBAL_CONCURRENCY', 2),
        'intake_per_batch_concurrency' => (int) env('GEMINI_INTAKE_PER_BATCH_CONCURRENCY', 2),
        'intake_slot_lease_seconds' => (int) env('GEMINI_INTAKE_SLOT_LEASE_SECONDS', 240),
        'intake_slot_requeue_seconds' => (int) env('GEMINI_INTAKE_SLOT_REQUEUE_SECONDS', 3),
        'intake_adaptive_window_seconds' => (int) env('GEMINI_INTAKE_ADAPTIVE_WINDOW_SECONDS', 180),
        'intake_adaptive_min_concurrency' => (int) env('GEMINI_INTAKE_ADAPTIVE_MIN_CONCURRENCY', 1),
        'intake_adaptive_overload_threshold' => (int) env('GEMINI_INTAKE_ADAPTIVE_OVERLOAD_THRESHOLD', 3),
    ],

];
