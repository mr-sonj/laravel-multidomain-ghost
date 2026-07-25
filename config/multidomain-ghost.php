<?php

return [
    'url' => env('GHOST_URL', ''),
    'content_key' => env('GHOST_CONTENT_KEY', ''),
    'admin_url' => env('GHOST_ADMIN_URL', ''),
    'admin_key' => env('GHOST_ADMIN_KEY', ''),
    'api_mode' => env('GHOST_API_MODE', 'auto'),
    'api_version' => env('GHOST_API_VERSION', 'v6.0'),
    'jwt_audience' => env('GHOST_JWT_AUDIENCE', '/admin/'),
    'verify_ssl' => filter_var(env('GHOST_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),
    'timeout' => (int) env('GHOST_TIMEOUT', 10),
    'retry_times' => (int) env('GHOST_RETRY_TIMES', 2),
    'retry_sleep' => (int) env('GHOST_RETRY_SLEEP', 200),
    'cache_ttl' => (int) env('GHOST_CACHE_TTL', 60 * 60 * 24 * 30),
    'domain_tag_prefix' => env('GHOST_DOMAIN_TAG_PREFIX', 'hash-'),
    'webhook_secret' => env('GHOST_WEBHOOK_SECRET', ''),
    'allow_unsigned_webhooks' => filter_var(
        env('GHOST_ALLOW_UNSIGNED_WEBHOOKS', false),
        FILTER_VALIDATE_BOOL,
    ),
    'webhook_tolerance' => (int) env('GHOST_WEBHOOK_TOLERANCE', 300),
    'domains' => array_values(array_filter(array_map(
        static fn (string $domain): string => strtolower(trim($domain)),
        explode(',', (string) env('GHOST_REGISTERED_DOMAINS', '')),
    ))),
    'routes' => [
        'webhook' => [
            'enabled' => filter_var(env('GHOST_WEBHOOK_ROUTE_ENABLED', true), FILTER_VALIDATE_BOOL),
            'uri' => env('GHOST_WEBHOOK_ROUTE', 'webhook/ghost/post'),
            'middleware' => ['throttle:500,1'],
        ],
    ],
    'views' => [
        'page' => 'multidomain-ghost::page',
        'blog' => 'multidomain-ghost::blog',
    ],
    'robots' => [
        'content_signal' => env('ROBOTS_CONTENT_SIGNAL', ''),
    ],
    'enrichers' => [],
    'transformer' => null,
];
