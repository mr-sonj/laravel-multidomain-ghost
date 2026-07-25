# Laravel Multi-Domain Ghost

A Laravel package for serving multiple isolated domains from one application while using Ghost as a headless CMS.

It provides:

- Per-domain storage, configuration, views and Vite CSS entries.
- Ghost Content API integration scoped by an internal domain tag.
- Post and page lookup through their canonical URL.
- Domain-aware caching and signed Ghost webhooks.
- Ready-to-use page, blog, sitemap, RSS feed, robots and ads endpoints.
- Optional domain enrichers and content transformers.

## Requirements

- PHP 8.3 or 8.4.
- Laravel 11, 12 or 13.
- A Ghost site with a Custom Integration and Content API key.

## Quick start

### 1. Install the package

```bash
composer require mr-sonj/laravel-multidomain-ghost
php artisan ghost:install
```

`ghost:install`:

- Publishes `config/multidomain-ghost.php`.
- Updates `bootstrap/app.php` to use the package's multi-domain `Application`.
- Adds the required Ghost variables to `.env` and `.env.example`.
- Creates `config/domain.php`.

The command exits with an error when it cannot safely update `bootstrap/app.php`. It does not silently report a complete installation.

### 2. Configure Ghost

Create a Custom Integration in Ghost Admin and update `.env`:

```dotenv
GHOST_URL=https://cms.example.com
GHOST_CONTENT_KEY=your_content_api_key
GHOST_WEBHOOK_SECRET=use_a_long_random_value
```

The URL may be either the Ghost site URL or a Ghost API base URL. The package normalizes both forms.

### 3. Add a domain

```bash
php artisan domain:add example.com
```

This command creates:

- `storage/example_com/` with isolated Laravel storage directories.
- `config/domains/example_com.php` with domain-specific overrides.
- `resources/views/example_com/` containing `main`, `home`, `page`, `blog`, `post` and `contact` views.
- `resources/css/example_com.css` with framework-independent base styles.
- A Vite input entry when a supported `input: [...]` array is found.
- A `Route::domain('example.com')` group in `routes/web.php`.
- A registry entry in `config/domain.php`.

It also updates `_setup/multi_domain_local_herd.conf` when that optional file exists. Unsupported Vite or route structures produce a warning and a manual instruction.

Use `--force` only when you want to overwrite the generated domain views and CSS:

```bash
php artisan domain:add example.com --force
```

### 4. Prepare content in Ghost

Every Ghost post or page served by a domain needs:

| Attribute | Example | Purpose |
| --- | --- | --- |
| Canonical URL | `https://example.com/about` | Matches the incoming Laravel URL. |
| Internal domain tag | `#example-com` | Limits the content to `example.com`; its slug is `hash-example-com`. |
| Optional internal type tag | `#page` | Excludes static content from blog listings. |

The package constructs lookup URLs from `https://`, the current request host and its path. The Ghost canonical URL must match that URL, with or without a trailing slash.

After publishing a Ghost page with canonical URL `https://example.com/`, open the domain in a browser. The generated home route and view are ready to render it.

## Generated routes

`domain:add example.com` adds the following routes:

| URL | Controller action | Response |
| --- | --- | --- |
| `/` | `page` | Domain home Blade view. |
| `/blog` | `blog` | Paginated Ghost post listing. |
| `/blog/{slug}` | `page` | Domain post Blade view. |
| `/robots.txt` | `robots` | Plain-text robots policy. |
| `/sitemap.xml` | `sitemap` | XML sitemap. |
| `/feed` | `feed` | RSS 2.0 feed. |
| `/ads.txt` | `ads` | Plain-text ads configuration. |

Add application-specific page routes explicitly:

```php
use Illuminate\Support\Facades\Route;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;

Route::domain('example.com')->group(function () {
    Route::get('/about', [GhostController::class, 'page'])
        ->defaults('viewPath', 'example_com/page')
        ->name('example_about');

    Route::get('/contact', [GhostController::class, 'page'])
        ->defaults('viewPath', 'example_com/contact')
        ->name('example_contact');
});
```

`page()` passes `$content` and `$seo` to the view. `blog()` additionally passes `$dataBlog` and `$page`.

If `viewPath` is omitted, the package uses `multidomain-ghost::page` or `multidomain-ghost::blog`.

## Domain configuration

Domain-specific overrides use Laravel dot notation:

```php
<?php

return [
    'app.name' => 'Example Website',
    'app.url' => 'https://example.com',
    'cache.prefix' => 'example_com_cache',
];
```

When `example.com` is active, the package loads `config/domains/example_com.php` after the base Laravel configuration.

Console commands can opt into the same domain context:

```bash
php artisan domain --domain=example.com
php artisan queue:work --domain=example.com
php artisan optimize --domain=example.com
```

Other management commands:

```bash
# Show registered domains, tags, storage and config status.
php artisan domain:list

# Unregister a domain while preserving its config and generated files.
php artisan domain:remove example.com

# Also delete the domain storage directory.
php artisan domain:remove example.com --force
```

## Ghost API configuration

The common options are:

| Environment variable | Default | Description |
| --- | --- | --- |
| `GHOST_URL` | none | Ghost site or Content API base URL. |
| `GHOST_CONTENT_KEY` | none | Content API key. |
| `GHOST_API_VERSION` | `v6.0` | Value of the Ghost `Accept-Version` header. |
| `GHOST_CACHE_TTL` | 30 days | Cached content lifetime in seconds. |
| `GHOST_TIMEOUT` | `10` | HTTP timeout in seconds. |
| `GHOST_RETRY_TIMES` | `2` | HTTP retry count. |
| `GHOST_VERIFY_SSL` | `true` | Enable TLS certificate verification. |
| `GHOST_WEBHOOK_SECRET` | none | HMAC secret shared with the Ghost webhook. |

The package can optionally use the Ghost Admin API in local development:

```dotenv
GHOST_ADMIN_URL=https://cms.example.com
GHOST_ADMIN_KEY=id:hex_secret
GHOST_API_MODE=auto
```

`auto` uses Admin API credentials only in the local environment when both are present. Set the mode to `content` or `admin` to select one explicitly.

See `config/multidomain-ghost.php` for retry delays, webhook tolerance, route middleware, view names and extension bindings.

## Webhook and cache invalidation

Create a Ghost webhook pointing to:

```text
https://your-laravel-app.com/webhook/ghost/post
```

The route is registered by the package outside the `web` middleware group, so no CSRF exception is needed. Requests are verified with `X-Ghost-Signature` and `GHOST_WEBHOOK_SECRET`.

Unsigned webhooks are rejected by default. `GHOST_ALLOW_UNSIGNED_WEBHOOKS=true` should only be used in a controlled development environment.

Post, page, slug and blog listing caches are invalidated for affected registered domains.

## Custom domain enricher

An enricher adds application data to `$content` before rendering:

```php
<?php

namespace App\Services\example_com;

use MrSonj\MultiDomainGhost\Contracts\DomainEnricherInterface;

class ExampleComEnricher implements DomainEnricherInterface
{
    public function enrich(array $content, string $canonicalUrl): array
    {
        $content['products'] = Product::query()->latest()->take(5)->get();

        return $content;
    }
}
```

The package discovers `App\Services\{domain_key}\{StudlyDomainKey}Enricher`. An explicit class can instead be set in `multidomain-ghost.enrichers`.

## Custom content transformer

A transformer modifies normalized Ghost content before it reaches controllers and views:

```php
<?php

namespace App\Services;

use MrSonj\MultiDomainGhost\Contracts\ContentTransformerInterface;

class GhostContentTransformer implements ContentTransformerInterface
{
    public function transform(array $content, string $domain): array
    {
        // Transform $content['html'] or add normalized attributes.

        return $content;
    }
}
```

`App\Services\GhostContentTransformer` is discovered automatically. It can also be configured through `multidomain-ghost.transformer`.

## Custom sitemap or feed rendering

The default routes return an XML sitemap and RSS 2.0 feed. Applications that need custom rendering can use:

```php
$links = $controller->sitemapLinks();
$feed = $controller->feedData($request);
```

These methods return normalized arrays without imposing a view or serialization format.

## Upgrading from JSON sitemap/feed responses

`sitemap()` and `feed()` now return standards-compliant XML instead of normalized JSON. Code that consumed their previous JSON responses should call `sitemapLinks()` and `feedData()` directly.

## License

MIT
