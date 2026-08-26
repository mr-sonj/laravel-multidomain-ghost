<?php

/*
|--------------------------------------------------------------------------
| Multi-domain Ghost
|--------------------------------------------------------------------------
|
| Only the keys worth deciding per deployment live here. Everything else -
| timeouts, retries, cache prefixes, route paths, view names, the robots.txt
| body, the SEO image template - already has a default inside the package.
| Add a key back to this file to override one; see the README for the list.
|
*/

return [

    // Ghost site URL or Content API base URL, and the Content API key.
    'url' => env('GHOST_URL', ''),
    'content_key' => env('GHOST_CONTENT_KEY', ''),

    // Admin API. Only needed to read drafts, which is what makes previewing
    // unpublished posts locally work.
    'admin_url' => env('GHOST_ADMIN_URL', ''),
    'admin_key' => env('GHOST_ADMIN_KEY', ''),

    // auto: Admin API in local when its credentials are present, Content API
    // everywhere else. Force one with 'admin' or 'content'.
    'api_mode' => env('GHOST_API_MODE', 'auto'),

    // Sent as Accept-Version. Lower it to match an older Ghost install.
    'api_version' => env('GHOST_API_VERSION', 'v6.0'),

    'timeout' => (int) env('GHOST_TIMEOUT', 10),

    'cache' => [
        // Unset keeps caching off in local and on everywhere else. Set
        // GHOST_CACHE_ENABLED=true to reproduce a cache bug without deploying.
        'enabled' => env('GHOST_CACHE_ENABLED'),

        // Leave null to let the package provision a dedicated store derived from
        // your default one. Ghost keys already contain their domain, so this store
        // is deliberately shared across domains: that is what lets a webhook
        // arriving on one domain invalidate any other domain.
        'store' => env('GHOST_CACHE_STORE'),

        'ttl' => (int) env('GHOST_CACHE_TTL', 60 * 60 * 24 * 30),
    ],

    'routes' => [
        'auto_register' => filter_var(env('GHOST_ROUTES_AUTO_REGISTER', true), FILTER_VALIDATE_BOOL),

        // Resolves any otherwise unmatched path against Ghost by canonical URL, so
        // /about needs no route of its own. Registered last, after your own routes.
        // Every unmatched URL becomes a Ghost lookup though - scanner traffic included -
        // so this stays opt-in.
        'catch_all' => filter_var(env('GHOST_ROUTES_CATCH_ALL', false), FILTER_VALIDATE_BOOL),
    ],

    'webhook_secret' => env('GHOST_WEBHOOK_SECRET', ''),

    // Upper bound for ?page= on blog and feed routes. Every distinct page number
    // is a separate cache entry and a separate Ghost request, so leaving it
    // unbounded lets a crawler amplify traffic against the CMS.
    'max_blog_page' => (int) env('GHOST_MAX_BLOG_PAGE', 200),

    'robots' => [
        // Consulted only when the domain has no resources/domains/{domain_key}/robots.txt.
        // That file, when present, replaces the generated policy entirely.
        'content_signal' => env('GHOST_ROBOTS_CONTENT_SIGNAL', ''),
    ],

    // Per-domain classes are found by convention; map one here only when the class
    // name does not follow it. Transformer: null falls back to the convention class
    // App\Services\GhostContentTransformer.
    'enrichers' => [],
    'transformer' => null,

];
