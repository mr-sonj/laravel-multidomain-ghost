# Laravel Multi-Domain Ghost

Serve multiple isolated domains from one Laravel application, using Ghost as a headless CMS.

Each domain gets its own storage, config, routes, views and Vite entry. Ghost content is scoped by
an internal tag and looked up by canonical URL, with domain-aware caching, signed webhooks and
ready-made sitemap, robots, feed and blog routes.

**Requirements:** PHP 8.3+ · Laravel 11, 12 or 13 · a Ghost site with a Custom Integration key.

---

## Quick start

### 1. Install

```bash
composer require mr-sonj/laravel-multidomain-ghost
php artisan ghost:install
```

Publishes `config/multidomain-ghost.php`, adds the Ghost variables to `.env` / `.env.example`, and
switches `bootstrap/app.php` to the package's multi-domain `Application`. It fails loudly rather
than reporting success when `bootstrap/app.php` cannot be edited safely.

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

Scaffolds everything the domain needs — see [Per-domain layout](#per-domain-layout).

### 4. Publish content in Ghost

Every post or page served by a domain needs:

| Attribute | Example | Purpose |
| --- | --- | --- |
| Canonical URL | `https://example.com/about` | Matches the incoming Laravel URL. |
| Internal domain tag | `#example-com` | Limits the content to `example.com`. |
| Internal type tag (optional) | `#page` | Keeps static content out of blog listings. |

Lookup URLs are built from `https://` + the request host + its path, and must match the Ghost
canonical URL (trailing slash optional).

> [!WARNING]
> Ghost's default permalink is `/{slug}/` at the root. If your routes use a prefix such as
> `/blog/{slug}`, update the permalink/canonical URLs in Ghost to match.

### What you get

| URL | Controller action | Response |
| --- | --- | --- |
| `/` | `page` | Domain home Blade view. |
| `/blog` | `blog` | Paginated Ghost post listing. |
| `/blog/{slug}` | `page` | Domain post Blade view. |
| `/feed` | `feed` | RSS 2.0 feed. |
| `/sitemap.xml` | `sitemap` | XML sitemap. |
| `/robots.txt` | `robots` | The domain's own file, or a generated policy. |
| `/ads.txt`, `/llms.txt`, `/llms-full.txt` | `ads`, `llms`, `llmsFull` | Served verbatim — registered only for domains that own the file. |

`www.example.com` is 301-redirected to the apex domain (`routes.redirect_www`, on by default).

### Before deploying

```bash
php artisan domain:optimize
```

Config, route and event caches are stored **per domain** — a bare `php artisan config:cache` writes
a file no domain request ever reads.

---

## Per-domain layout

`domain:add example.com` creates, for key `example_com`:

| Path | Purpose |
| --- | --- |
| `storage/example_com/` | Sessions, logs, cache and compiled views for this domain. |
| `config/domains/example_com.php` | Registers the domain and overrides config with dot notation. |
| `routes/domains/example_com.php` | This domain's content routes (`/`, `/blog`, `/blog/{slug}`, `/feed`). |
| `resources/views/example_com/` | `main`, `home`, `page`, `post`, `blog`, `contact`, plus optional `robots.txt`, `ads.txt`, `llms.txt`, `llms-full.txt`. |
| `resources/css/example_com.css` + Vite input entry | Per-domain stylesheet. |

Route files load automatically from the domain registry, already inside the domain's route group,
so `routes/web.php` is never touched.

### Config overrides

```php
// config/domains/example_com.php
return [
    'app.name' => 'Example Website',
    'app.url' => 'https://example.com',
    'cache.prefix' => 'example_com_cache',

    // Package keys are overridable the same way:
    'multidomain-ghost.robots.disallow' => ['/cdn-cgi/', '/internal/'],
    'multidomain-ghost.seo.default_image' => 'https://cdn.example.net/social.png',
];
```

`og:site_name`, `twitter:site` and the locale come from JSON in the description of the domain's
Ghost primary tag, so editors change them without a deploy.

### Content routes

The scaffolded route file is plain Laravel — rename paths, add your own controllers, anything:

```php
use Illuminate\Support\Facades\Route;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;

Route::get('/news', [GhostController::class, 'blog'])
    ->name('example_com_blog')
    ->defaults('viewPath', 'example_com/blog');

Route::get('/pricing', [App\Http\Controllers\PricingController::class, 'index']);
```

`page()` passes `$content` and `$seo` to the view; `blog()` adds `$dataBlog` and `$page`. Without
`viewPath`, the package falls back to `multidomain-ghost::page` / `::blog`.

### robots.txt, ads.txt, llms.txt

`public/` is one webserver root shared by every domain, so these files live per-domain in the
domain's own view folder, `resources/views/{key}/`, beside its Blade files. `ads.txt`, `llms.txt`
and `llms-full.txt` are served verbatim and have no generated form — no file, no route.
`robots.txt`, when present, **replaces** the generated policy entirely, including the `Sitemap:`
line. All are sent as `text/plain`.

---

## Commands

```bash
php artisan domain                             # the active domain
php artisan domain:list                        # domains, tags, enrichers, storage/config status
php artisan domain:add example.com             # register and scaffold (idempotent)
php artisan domain:remove example.com          # unregister, clear caches, remove config
php artisan domain:remove example.com --force  # also delete the storage directory
php artisan domain:optimize                    # per-domain config/route/event caches
```

`domain:optimize` also takes `--clear`, `--pretend` and `--only=example.com`.

Console commands opt into a domain context with `--domain`:

```bash
php artisan queue:work --domain=example.com
php artisan optimize --domain=example.com
```

### Re-running domain:add

Re-running backfills whatever a newer package version added, without rewriting files that are
already correct. What each flag may replace:

| | bare | `--force` | `--force-routes` |
| --- | --- | --- | --- |
| `storage/{key}/`, `resources/views/{key}/`, Vite entry | created if missing | ↑ | ↑ |
| `config/domains/{key}.php` | **never replaced** | **never replaced** | **never replaced** |
| `resources/views/{key}/*.blade.php`, `resources/css/{key}.css` | kept | replaced | kept |
| `routes/domains/{key}.php` | kept | **kept** | replaced, after confirming |

Anything replaced is copied to `{file}.{timestamp}.bak` first. `config/domains/{key}.php` holds the
domain's identity and is what makes the domain exist at all, so nothing overwrites it.

---

## Configuration

`config/multidomain-ghost.php` carries only the keys worth deciding per deployment:

| Variable | Default | Description |
| --- | --- | --- |
| `GHOST_URL` | none | Ghost site or Content API base URL. |
| `GHOST_CONTENT_KEY` | none | Content API key. |
| `GHOST_ADMIN_URL` / `GHOST_ADMIN_KEY` | none | Admin API, `id:hex_secret`. Needed only to read drafts. |
| `GHOST_API_MODE` | `auto` | `auto` uses Admin credentials in `local` only; or force `content` / `admin`. |
| `GHOST_API_VERSION` | `v6.0` | `Accept-Version` header. |
| `GHOST_TIMEOUT` | `10` | HTTP timeout, seconds. |
| `GHOST_CACHE_ENABLED` | on outside `local` | Opt into caching locally to reproduce bugs. |
| `GHOST_CACHE_STORE` | auto-provisioned | Store Ghost content lives in. |
| `GHOST_CACHE_TTL` | 30 days | Cached content lifetime, seconds. |
| `GHOST_ROUTES_AUTO_REGISTER` | `true` | Register every registered domain's routes. |
| `GHOST_ROUTES_CATCH_ALL` | `false` | Resolve unmatched paths against Ghost. |
| `GHOST_WEBHOOK_SECRET` | none | HMAC secret shared with the Ghost webhook. |
| `GHOST_MAX_BLOG_PAGE` | `200` | Highest `?page=` served on blog and feed routes. |
| `GHOST_ROBOTS_CONTENT_SIGNAL` | none | `Content-Signal:` line in the generated robots.txt. |

Further keys are **not** in the published file but have package defaults; add the key to override:
`jwt_audience`, `verify_ssl`, `retry_times`, `retry_sleep`, `cache.prefix`, `cache.miss_ttl`,
`cache.empty_ttl`, `domain_tag_prefix`, `allow_unsigned_webhooks`, `webhook_tolerance`,
`routes.middleware`, `routes.redirect_www`, `routes.paths`, `routes.webhook.*`, `views.page`,
`views.blog`, `seo.default_image`, `robots.sitemap`, `robots.disallow`.

`routes.paths` maps the standard web files; it is merged over the defaults, so one entry can be
relocated or set to `null` on its own:

```php
'routes' => [
    'paths' => [
        'sitemap' => '/sitemap-index.xml',  // relocated
        'ads' => null,                      // not registered
        // robots, llms, llms_full stay at their defaults
    ],
],
```

`seo.default_image` and `robots.sitemap` expand `{domain}` and `{domain_key}` to the active hostname
and its directory-safe form (`example.com` / `example_com`).

---

## Caching and webhooks

Point a Ghost webhook at `https://your-app.com/webhook/ghost/post`. The route sits outside the `web`
group (no CSRF exception needed) and is verified with `X-Ghost-Signature` and `GHOST_WEBHOOK_SECRET`.
Unsigned webhooks are rejected unless `allow_unsigned_webhooks` is set — development only.

Ghost cache keys carry their own domain (`ghost:{domain}:...`), so they live in **one store shared
by every domain**. That is what makes invalidation deterministic: a webhook arriving on one domain
can purge any other. The store is derived from your default cache store and declared in
`cache.stores` at boot:

```bash
php artisan cache:clear multidomain-ghost
```

Declare it yourself once more than one thing depends on it — a domain that overrides `cache.default`
in its own config file otherwise derives a *different* store and stops receiving invalidation
(`domain:list` warns about this, and about two domains sharing a `cache.prefix`):

```php
// config/cache.php
'multidomain-ghost' => ['driver' => 'database', 'prefix' => 'multidomain_ghost'],
```

```dotenv
GHOST_CACHE_STORE=multidomain-ghost
```

`cache.miss_ttl` (300s) remembers "this URL has no content" so unknown URLs cannot hit Ghost on
every request; `cache.empty_ttl` (300s) keeps successful-but-empty responses short-lived.

**When Ghost is unreachable** — outage, revoked key, timeout — the failure is logged and turned into
"no content": pages 404, listings come back empty, the rest of the application keeps serving. Ghost
errors never surface as a 500.

---

## Deployment

Run `php artisan domain:optimize` and make sure each domain has its `storage/{domain_key}`
directory; without it the domain falls back to shared storage and shares sessions, logs and cached
config with every other domain.

`queue:work --domain=example.com` works out of the box. `queue:listen` spawns child processes, so it
needs the package's queue provider to forward the domain — the Laravel 11+ skeleton ships without a
`providers` key in `config/app.php`, so add one:

```php
'providers' => ServiceProvider::defaultProviders()->replace([
    Illuminate\Queue\QueueServiceProvider::class => MrSonj\MultiDomainGhost\Queue\QueueServiceProvider::class,
])->toArray(),
```

---

## Extending

**Domain enricher** — adds application data to `$content` before rendering. Implement
`DomainEnricherInterface`; `App\Services\{domain_key}\{StudlyDomainKey}Enricher` is discovered
automatically. Domain keys that cannot be a PHP namespace (`10mailbox.com`, `my-site.com`) must be
mapped in the `enrichers` config array.

```php
public function enrich(array $content, string $canonicalUrl): array
{
    $content['products'] = Product::query()->latest()->take(5)->get();

    return $content;
}
```

**Content transformer** — modifies normalized Ghost content before it reaches controllers and views.
Implement `ContentTransformerInterface`; `App\Services\GhostContentTransformer` is discovered
automatically, or set `multidomain-ghost.transformer`.

**Custom sitemap or feed** — take the normalized arrays instead of the default XML:
`$controller->sitemapLinks()` and `$controller->feedData($request)`.

**Manual route registration** — set `GHOST_ROUTES_AUTO_REGISTER=false` and declare each domain in
`routes/web.php`:

```php
Route::ghostDomain('example.com', function () {
    require base_path('routes/domains/example_com.php');
});
```

**Catch-all** — `GHOST_ROUTES_CATCH_ALL=true` resolves any unmatched path against Ghost by canonical
URL, so `/about` works without a route declaration. It is always registered last, so it never
shadows a real route.

> [!WARNING]
> A catch-all turns **every** unmatched URL into a Ghost lookup, including `/.env` and other scanner
> traffic, and each distinct URL takes its own negative-cache entry. Leave it off unless you need it,
> and keep rate limiting in front of it.

---

## Not in scope

`.env.{domain}` files and secrets; domain-specific business logic; Nginx, DNS, TLS, Herd or Forge
configuration; production queue workers and schedulers; database migrations; and anything that
replaces Ghost Admin or its authoring workflow.

## License

MIT
