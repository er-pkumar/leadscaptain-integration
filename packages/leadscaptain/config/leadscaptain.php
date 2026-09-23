<?php

declare(strict_types=1);

$csvToInts = static fn (string $value): array => array_map('intval', array_filter(explode(',', $value), 'strlen'));

return [

    /*
    | API connection
    | The live OpenAPI spec documents the endpoint as /api/v1/leads with
    | `page` and `limit` parameters, so both are configurable.
    */
    'base_url' => env('LEADSCAPTAIN_BASE_URL', 'https://api.leadscaptain.com'),
    'api_key' => env('LEADSCAPTAIN_API_KEY'),
    'leads_path' => env('LEADSCAPTAIN_LEADS_PATH', '/api/v1/leads'),
    'count_path' => env('LEADSCAPTAIN_COUNT_PATH', '/api/v1/leads/count'),

    /*
    | Authentication
    | The API accepts an API key header (apiKeyAuth) or a Bearer token
    | (bearerAuth). scheme: "api_key" or "bearer".
    */
    'auth' => [
        'scheme' => env('LEADSCAPTAIN_AUTH_SCHEME', 'api_key'),
        'header' => env('LEADSCAPTAIN_API_KEY_HEADER', 'X-API-Key'),
    ],

    'pagination' => [
        'page_param' => env('LEADSCAPTAIN_PAGE_PARAM', 'page'),
        'page_size_param' => env('LEADSCAPTAIN_PAGE_SIZE_PARAM', 'limit'),
        'page_size' => (int) env('LEADSCAPTAIN_PAGE_SIZE', 100),
        // Safety cap used when the last page cannot be determined
        'max_pages' => (int) env('LEADSCAPTAIN_MAX_PAGES', 1000),
    ],

    'http' => [
        'timeout' => (int) env('LEADSCAPTAIN_TIMEOUT', 30),
        'connect_timeout' => (int) env('LEADSCAPTAIN_CONNECT_TIMEOUT', 10),
        'concurrency' => (int) env('LEADSCAPTAIN_CONCURRENCY', 10),
    ],

    /*
    | Retries
    | Spec section 5: 5xx/timeouts retried with 1s, 5s, 30s.
    | Spec section 4: 429 retried with 1s, 2s, 4s.
    | A Retry-After header from the API takes precedence when present.
    */
    'retry' => [
        'times' => (int) env('LEADSCAPTAIN_RETRY_TIMES', 3),
        'backoff_ms' => $csvToInts((string) env('LEADSCAPTAIN_RETRY_BACKOFF_MS', '1000,5000,30000')),
        'rate_limit_backoff_ms' => $csvToInts((string) env('LEADSCAPTAIN_RATE_LIMIT_BACKOFF_MS', '1000,2000,4000')),
        'retry_on_status' => [429, 500, 502, 503, 504],
    ],

    'rate_limit' => [
        'max_requests' => (int) env('LEADSCAPTAIN_RATE_LIMIT', 60),
        'window_seconds' => (int) env('LEADSCAPTAIN_RATE_LIMIT_WINDOW', 60),
        'redis_connection' => env('LEADSCAPTAIN_REDIS_CONNECTION', 'default'),
    ],

    'queue' => [
        'connection' => env('LEADSCAPTAIN_QUEUE_CONNECTION'),
        'name' => env('LEADSCAPTAIN_QUEUE', 'leadscaptain'),
    ],

    'logging' => [
        'channel' => env('LEADSCAPTAIN_LOG_CHANNEL', 'leadscaptain'),
        'stream' => env('LEADSCAPTAIN_LOG_STREAM', 'php://stderr'),
        'level' => env('LEADSCAPTAIN_LOG_LEVEL', 'info'),
    ],

    'notifications' => [
        'mail_to' => env('LEADSCAPTAIN_ALERT_MAIL'),
    ],
];
