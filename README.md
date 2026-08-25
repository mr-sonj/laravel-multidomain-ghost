# Laravel Multi-Domain Ghost

Serve multiple isolated domains from one Laravel application, using Ghost as a headless CMS.

Per-domain storage, configuration, views and Vite entries. Ghost content scoped by an internal
tag and looked up by canonical URL. Domain-aware caching, signed webhooks, and ready-made page,
blog, sitemap, feed, robots and ads routes.

**Requirements:** PHP 8.3 or 8.4 · Laravel 11, 12 or 13 · a Ghost site with a Custom Integration
and Content API key.

---

# Quick start

Four steps to a live domain.

### 1. Install

```bash
composer require mr-sonj/laravel-multidomain-ghost
php artisan ghost:install
```

Publishes `config/multidomain-ghost.php`, adds the Ghost variables to
`.env` and `.env.example`, and switches `bootstrap/app.php` to the package's multi-domain
`Application`. It exits with an error rather than reporting a complete install when it cannot edit
`bootstrap/app.php` safely.

### 2. Point at Ghost

Create a Custom Integration in Ghost Admin, then fill in `.env`:

```dotenv
GHOST_URL=https://cms.example.com
GHOST_CONTENT_KEY=your_content_api_key
GHOST_WEBHOOK_SECRET=use_a_long_random_value
```

`GHOST_URL` accepts either the Ghost site URL or the API base URL.

### 3. Add a domain

```bash
php artisan domain:add example.com
```

Creates `storage/example_com/`, `config/domains/example_com.php` (which registers the domain),
`resources/views/example_com/` (`main`, `home`, `page`, `blog`, `post`, `contact`),
`resources/css/example_com.css`, a Vite input entry, and a `Route::domain()` group in `routes/web.php`.
Unsupported Vite or route structures produce a warning with manual instructions, and
`_setup/multi_domain_local_herd.conf` is updated when that optional file exists. Pass `--force` only
to overwrite generated views and CSS.

### 4. Publish content in Ghost

Every post or page served by a domain needs:

| Attribute | Example | Purpose |
| --- | --- | --- |
| Canonical URL | `https://example.com/about` | Matches the incoming Laravel URL. |
| Internal domain tag | `#example-com` | Limits the content to `example.com`. |
| Internal type tag (optional) | `#page` | Keeps static content out of blog listings. |

Lookup URLs are built from `https://` + the request host + its path; the Ghost canonical URL must
match, with or without a trailing slash. Publish a page with canonical URL `https://example.com/`
and open the domain — the generated home route and view render it.

### What you get

| URL | Action | Response |
| --- | --- | --- |
| `/` | `page` | Domain home Blade view. |
| `/blog` | `blog` | Paginated Ghost post listing. |
| `/blog/{slug}` | `page` | Domain post Blade view. |
| `/robots.txt` | `robots` | Plain-text robots policy. |
| `/sitemap.xml` | `sitemap` | XML sitemap. |
| `/feed` | `feed` | RSS 2.0 feed. |
| `/ads.txt` | `ads` | Plain-text ads configuration. |

A second group 301-redirects `www.example.com` to the apex domain; without it the `www` host
matches no route and 404s.

### Before deploying

```bash
php artisan domain:optimize
```

Config, route and event caches are stored **per domain** — a bare `php artisan config:cache` writes
a file no domain request ever reads. See [Deployment](#deployment) for the rest.

---

# Customization

Everything below is optional.

## Your own page routes

```php
use Illuminate\Support\Facades\Route;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;

Route::domain('example.com')->group(function () {
    Route::get('/about', [GhostController::class, 'page'])
        ->defaults('viewPath', 'example_com/page')
        ->name('example_about');
});
```

`page()` passes `$content` and `$seo` to the view; `blog()` adds `$dataBlog` and `$page`. Without
`viewPath` the package falls back to `multidomain-ghost::page` / `multidomain-ghost::blog`.

## Per-domain configuration

`config/domains/example_com.php` is loaded after the base configuration when `example.com` is
active. Overrides use dot notation:

```php
return [
    'app.name' => 'Example Website',
    'app.url' => 'https://example.com',
    'cache.prefix' => 'example_com_cache',
];
```

Console commands opt into the same context with `--domain`:

```bash
php artisan queue:work --domain=example.com
php artisan optimize --domain=example.com
```

## Domain commands

```bash
php artisan domain:list                        # domains, tags, storage and config status
php artisan domain:remove example.com          # unregister, clear its caches and remove its config
php artisan domain:remove example.com --force  # also delete the storage directory
```

Generated Ghost routes check this file-backed registry at request time. Removing a domain therefore
returns 404 even if its generated route block is still present in `routes/web.php`.

## Ghost API options

| Variable | Default | Description |
| --- | --- | --- |
| `GHOST_URL` | none | Ghost site or Content API base URL. |
| `GHOST_CONTENT_KEY` | none | Content API key. |
| `GHOST_API_VERSION` | `v6.0` | Ghost `Accept-Version` header. |
| `GHOST_TIMEOUT` | `10` | HTTP timeout in seconds. |
| `GHOST_RETRY_TIMES` | `2` | HTTP retry count. |
| `GHOST_VERIFY_SSL` | `true` | TLS certificate verification. |
| `GHOST_WEBHOOK_SECRET` | none | HMAC secret shared with the Ghost webhook. |
| `GHOST_CACHE_ENABLED` | `true` in production | Opt into caching locally to reproduce bugs. |
| `GHOST_CACHE_TTL` | 30 days | Cached content lifetime, in seconds. |
| `GHOST_CACHE_MISS_TTL` | `300` | Lifetime of a cached "not found" answer. |
| `GHOST_CACHE_EMPTY_TTL` | `300` | Lifetime of a cached empty response. |
| `GHOST_CACHE_PREFIX` | `multidomain_ghost` | Prefix of the shared Ghost cache store. |
| `GHOST_MAX_BLOG_PAGE` | `200` | Highest `?page=` served on blog and feed routes; past it, 404. |
| `GHOST_SEO_DEFAULT_IMAGE` | — | Template for the fallback social image. |

The Admin API can stand in during local development:

```dotenv
GHOST_ADMIN_URL=https://cms.example.com
GHOST_ADMIN_KEY=id:hex_secret
GHOST_API_MODE=auto
```

`auto` uses Admin credentials only in the local environment when both are present; `content` and
`admin` select one explicitly. See `config/multidomain-ghost.php` for retry delays, webhook
tolerance, route middleware, view names and extension bindings.

## Webhooks and cache invalidation

Point a Ghost webhook at `https://your-laravel-app.com/webhook/ghost/post`. The route is registered
outside the `web` middleware group, so no CSRF exception is needed; requests are verified with
`X-Ghost-Signature` and `GHOST_WEBHOOK_SECRET`. Unsigned webhooks are rejected unless
`GHOST_ALLOW_UNSIGNED_WEBHOOKS=true`, which belongs only in a controlled development environment.

Post, page, slug and blog listing caches are invalidated for every affected registered domain.

### Where Ghost content is cached

Ghost cache keys already carry their own domain (`ghost:{domain}:...`), so they live in **one store
shared by every domain** rather than under each domain's `cache.prefix`. That is what makes
invalidation deterministic: a webhook arriving on one domain can purge any other.

The store is derived automatically from your default cache store — same driver, same connection,
fixed prefix — and declared in `cache.stores` at boot, so artisan can address it directly:

```bash
php artisan cache:clear multidomain-ghost
```

```php
'cache' => [
    'store' => env('GHOST_CACHE_STORE'),          // null: auto-provision
    'prefix' => env('GHOST_CACHE_PREFIX', 'multidomain_ghost'),
    'ttl' => (int) env('GHOST_CACHE_TTL', 60 * 60 * 24 * 30),
    'miss_ttl' => (int) env('GHOST_CACHE_MISS_TTL', 300),
    'empty_ttl' => (int) env('GHOST_CACHE_EMPTY_TTL', 300),
],
```

`miss_ttl` remembers "this URL has no content" so an unknown URL cannot reach Ghost on every
request; `0` disables it. `empty_ttl` keeps successful-but-empty responses short-lived, so a
mistyped domain tag cannot freeze an empty sitemap for the full TTL.

Own the declaration yourself once more than one thing depends on it:

```php
// config/cache.php
'multidomain-ghost' => ['driver' => 'database', 'prefix' => 'multidomain_ghost'],
```

```dotenv
GHOST_CACHE_STORE=multidomain-ghost
```

That also closes the one way the shared store comes apart: the auto-provisioned store derives from
`cache.default`, so a domain overriding `cache.default` in its `config/domains/{key}.php` derives a
*different* store and stops receiving invalidation. `domain:list` warns about that, and about two
domains sharing one `cache.prefix` — which still matters for everything else your application
caches, sessions on a cache-backed driver included.

### When Ghost is unreachable

Upstream failures — an outage, a revoked key, a timeout — are logged and turned into "no content".
Pages 404, listings come back empty, the rest of the application keeps serving. Ghost errors never
surface as a 500.

## Deployment

```bash
php artisan domain:optimize                    # config, route and event caches, every domain
php artisan domain:optimize --pretend          # preview the commands
php artisan domain:optimize --only=example.com # one domain
php artisan domain:optimize --clear            # clear instead of build
```

Each domain also needs its `storage/{domain_key}` directory — `domain:add` creates it. Without it
the domain falls back to shared storage and shares sessions, logs and cached config with every
other domain.

### Queues

`queue:work --domain=example.com` works out of the box. `queue:listen` spawns child processes, so
it needs the package's queue provider to forward the domain. The Laravel 11+ skeleton ships without
a `providers` key in `config/app.php`; add one:

```php
'providers' => ServiceProvider::defaultProviders()->replace([
    Illuminate\Queue\QueueServiceProvider::class => MrSonj\MultiDomainGhost\Queue\QueueServiceProvider::class,
])->toArray(),
```

## Domain enricher

An enricher adds application data to `$content` before rendering:

```php
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

`App\Services\{domain_key}\{StudlyDomainKey}Enricher` is discovered automatically. The convention
puts the domain key in a PHP namespace, which cannot start with a digit or contain a hyphen, so
domains such as `10mailbox.com` or `my-site.com` **must** be mapped explicitly:

```php
'enrichers' => [
    '10mailbox.com' => App\Services\Tenmailbox\TenmailboxEnricher::class,
],
```

`domain:list` shows which enricher each domain resolves to, or `none`.

## Content transformer

A transformer modifies normalized Ghost content before it reaches controllers and views:

```php
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

`App\Services\GhostContentTransformer` is discovered automatically, or configure
`multidomain-ghost.transformer`.

## Custom sitemap or feed rendering

The default routes return an XML sitemap and RSS 2.0 feed. To render your own, take the normalized
arrays instead — no view or serialization format imposed:

```php
$links = $controller->sitemapLinks();
$feed = $controller->feedData($request);
```

These are also the replacement for the JSON that `sitemap()` and `feed()` returned before they
became standards-compliant XML.

---

## Not in scope

Left to the consumer application or deployment environment: `.env.{domain}` files and secrets;
registering public page routes per domain; automatic robots/sitemap/feed route registration;
sitemap, RSS or Atom Blade structures; any dependency on `artesaos/seotools`; domain-specific
business logic; brand-specific HTML or title transformations; `vite.config.js` updates; Nginx, DNS,
TLS, Herd or Forge configuration; production queue workers and scheduler services; database
migrations; and anything that replaces Ghost Admin or its authoring workflow.

## License

MIT
