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
    'cache' => [
        // Leave null to let the package provision a dedicated store derived from
        // your default one. Ghost keys already contain their domain, so this store
        // is deliberately shared across domains: that is what lets a webhook
        // arriving on one domain invalidate any other domain.
        'store' => env('GHOST_CACHE_STORE'),
        // Leave unset to keep caching off in local and on everywhere else. Set
        // GHOST_CACHE_ENABLED=true to reproduce a cache bug without deploying.
        'enabled' => env('GHOST_CACHE_ENABLED'),
        'prefix' => env('GHOST_CACHE_PREFIX', 'multidomain_ghost'),
        'ttl' => (int) env('GHOST_CACHE_TTL', 60 * 60 * 24 * 30),
        // Remembering "not found" keeps unknown URLs from reaching Ghost on every hit.
        'miss_ttl' => (int) env('GHOST_CACHE_MISS_TTL', 300),
        // Empty-but-successful responses expire quickly so a mistyped tag cannot
        // freeze an empty sitemap in place for the full TTL.
        'empty_ttl' => (int) env('GHOST_CACHE_EMPTY_TTL', 300),
    ],
    'domain_tag_prefix' => env('GHOST_DOMAIN_TAG_PREFIX', 'hash-'),
    // Upper bound for ?page= on blog and feed routes. Every distinct page number
    // is a separate cache entry and a separate Ghost request, so leaving it
    // unbounded lets a crawler amplify traffic against the CMS.
    'max_blog_page' => (int) env('GHOST_MAX_BLOG_PAGE', 200),
    'webhook_secret' => env('GHOST_WEBHOOK_SECRET', ''),
    'allow_unsigned_webhooks' => filter_var(
        env('GHOST_ALLOW_UNSIGNED_WEBHOOKS', false),
        FILTER_VALIDATE_BOOL,
    ),
    'webhook_tolerance' => (int) env('GHOST_WEBHOOK_TOLERANCE', 300),
    'routes' => [
        'auto_register' => filter_var(env('GHOST_ROUTES_AUTO_REGISTER', true), FILTER_VALIDATE_BOOL),
        // Resolves any otherwise unmatched path against Ghost by canonical URL, so
        // /about needs no route of its own. Registered last, after your own routes.
        // Every unmatched URL becomes a Ghost lookup though - scanner traffic included -
        // so this stays opt-in.
        'catch_all' => filter_var(env('GHOST_ROUTES_CATCH_ALL', false), FILTER_VALIDATE_BOOL),
        // Only the standard files, whose location is dictated by the crawlers reading
        // them rather than chosen by the site. null leaves that route unregistered.
        // Content routes are an editorial choice and vary per domain, so they live in
        // routes/domains/{domain_key}.php instead of in one map shared by every domain.
        'paths' => [
            'sitemap' => '/sitemap.xml',
            'robots' => '/robots.txt',
            'ads' => '/ads.txt',
        ],
        'middleware' => ['web'],
        'redirect_www' => filter_var(env('GHOST_ROUTES_REDIRECT_WWW', true), FILTER_VALIDATE_BOOL),
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
    // {domain} and {domain_key} expand to the active hostname and its
    // directory-safe form (example.com / example_com).
    'seo' => [
        'default_image' => env(
            'GHOST_SEO_DEFAULT_IMAGE',
            'https://{domain}/img/{domain_key}/apple-touch-icon.png',
        ),
    ],
    'robots' => [
        'content_signal' => env('ROBOTS_CONTENT_SIGNAL', ''),
        'sitemap' => env('GHOST_ROBOTS_SITEMAP', 'https://{domain}/sitemap.xml'),
        'disallow' => ['/cdn-cgi/'],
    ],
    'ads' => [
        'txt' => env('GHOST_ADS_TXT', ''),
    ],
    'enrichers' => [],
    'transformer' => null,
];
