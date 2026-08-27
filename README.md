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
`routes/domains/example_com.php` (this domain's content routes), `resources/views/example_com/`
(`main`, `home`, `page`, `blog`, `post`, `contact`), `resources/css/example_com.css`, and a Vite
input entry.

`routes/domains/example_com.php` is where `/`, `/blog`, `/blog/{slug}` and `/feed` live, and each
domain shapes them however it likes — that file is loaded automatically from the domain registry,
already inside the domain's route group, so `routes/web.php` is never touched. The standard files
(`/robots.txt`, `/sitemap.xml`) are registered for every domain without you declaring them, and
`/ads.txt` is registered for every domain that owns one. See
[Route customization](#route-customization--explicit-declaration) and
[Per-domain robots.txt and ads.txt](#per-domain-robotstxt-and-adstxt).

Unsupported Vite structures produce a warning with manual instructions, and
`_setup/multi_domain_local_herd.conf` is updated when that optional file exists. Pass `--force` only
to overwrite generated route files, views and CSS.

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

| Default URL | Action | Response |
| --- | --- | --- |
| `/` | `page` | Domain home Blade view. |
| `/blog` | `blog` | Paginated Ghost post listing. |
| `/blog/{slug}` | `page` | Domain post Blade view. |
| `/robots.txt` | `robots` | `resources/domains/{domain_key}/robots.txt` verbatim, or a generated policy. |
| `/sitemap.xml` | `sitemap` | XML sitemap. |
| `/feed` | `feed` | RSS 2.0 feed. |
| `/ads.txt` | `ads` | `resources/domains/{domain_key}/ads.txt` verbatim. Registered only for domains that have one. |

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

## Route customization & explicit declaration

By default, registered domains automatically load the content routes declared in their respective `routes/domains/{domain_key}.php` files, as well as the standard Ghost routes (`sitemap.xml`, `robots.txt`, `ads.txt`, `llms.txt`, `llms-full.txt`).

A `routes.paths` map in `config/multidomain-ghost.php` decides where those standard web files live. It is not in the published file — whatever you add is merged over the defaults, so one entry can be relocated, or set to `null` to leave that route unregistered, without restating the others:

| Key | Default path | Registered |
| --- | --- | --- |
| `sitemap` | `/sitemap.xml` | always |
| `robots` | `/robots.txt` | always — generated when the domain brings no file |
| `ads` | `/ads.txt` | only when the domain owns the file |
| `llms` | `/llms.txt` | only when the domain owns the file |
| `llms_full` | `/llms-full.txt` | only when the domain owns the file |

```php
'routes' => [
    'paths' => [
        'sitemap' => '/sitemap-index.xml',  // relocated
        'ads' => null,                      // not registered
        // robots, llms and llms_full stay at their defaults
    ],
],
```

The content routes (`/`, `/blog`, `/blog/{slug}`, `/feed`) are no longer part of this map. They are scaffolded into `routes/domains/{domain_key}.php` when you run `php artisan domain:add`, where you can customize them exactly as you would any other Laravel route:

```php
use Illuminate\Support\Facades\Route;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;
use App\Http\Controllers\PricingController;

Route::get('/', [GhostController::class, 'page'])
    ->name('example_com_home')
    ->defaults('viewPath', 'example_com/home');

Route::get('/news', [GhostController::class, 'blog'])
    ->name('example_com_blog')
    ->defaults('viewPath', 'example_com/blog');

Route::get('/news/{slug}', [GhostController::class, 'page'])
    ->name('example_com_post')
    ->defaults('viewPath', 'example_com/post')
    ->where('slug', '[A-Za-z0-9\-_]+');

// Domain-specific custom route:
Route::get('/pricing', [PricingController::class, 'index']);
```

`page()` passes `$content` and `$seo` to the view; `blog()` adds `$dataBlog` and `$page`. Without `viewPath`, the package falls back to `multidomain-ghost::page` / `multidomain-ghost::blog`.

If you prefer to load everything manually, set `GHOST_ROUTES_AUTO_REGISTER=false` and use the `Route::ghostDomain()` macro in `routes/web.php` for each domain:

```php
use Illuminate\Support\Facades\Route;

// Registers robots, sitemap, ads, and loads the closure:
Route::ghostDomain('example.com', function () {
    require base_path('routes/domains/example_com.php');
});
```

The `www` redirect is enabled by default. Set `routes.redirect_www` to `false` to opt out.

### Catch-all page resolution

Set `catch_all` (or `GHOST_ROUTES_CATCH_ALL=true`) to resolve any otherwise unmatched path
against Ghost by canonical URL, which is what lets `/about` or `/chinh-sach` work without a route
declaration each. It is always registered last — after the routes above and after anything a
`Route::ghostDomain()` closure adds, whenever it adds it — so it never shadows a real route.

> [!WARNING]
> A catch-all turns **every** unmatched URL into a Ghost lookup, including `/.env`,
> `/wp-admin/setup.php` and other scanner traffic. That undoes the protection the `post` slug
> constraint gives you, and each distinct URL takes its own negative-cache entry
> (`cache.miss_ttl`). Leave it off unless you need it, and keep rate limiting in front of it.

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

Route paths are the one thing this cannot override — see
[Route customization](#route-customization--explicit-declaration).

### Per-domain robots.txt, ads.txt and llms.txt

`resources/domains/{domain_key}/` is the third per-domain convention, alongside `config/domains/`
and `routes/domains/`. `php artisan domain:add` creates the directory; you add the files you want,
and each one you add gets its route:

```
resources/domains/example_com/
├── ads.txt
├── llms-full.txt
├── llms.txt
└── robots.txt
```

These files are per-domain by construction. `public/` is served by one webserver root shared by
every domain, so a file placed there answers for all of them — which is right for a shared asset
and wrong for a robots policy or a publisher's seller list.

**`ads.txt`** is served verbatim, and comes from this file only — there is no shared configuration
value, because an ads.txt belongs to one publisher account. `/ads.txt` is registered only for
domains that own the file. A missing or empty file leaves the route unregistered rather than
serving an empty body: an empty ads.txt returned with a 200 claims the domain authorises no
sellers, which is not the claim a domain without an ads.txt is making.

**`llms.txt`** and **`llms-full.txt`** follow ads.txt exactly: served verbatim, registered only for
the domains that own them, and each independent of the other — a domain can publish `llms.txt`
without `llms-full.txt`. They have no generated form. The package could assemble one from Ghost's
posts, but llms.txt is an editorial statement of what a site wants read and in what order, not an
inventory of what it happens to contain, so a generated one would put words in the publisher's
mouth. All three are sent as `text/plain`.

**`robots.txt`**, when present, **replaces** the generated policy entirely — the `Sitemap:` line
included, which you then write yourself:

```
User-agent: GPTBot
Disallow: /

User-agent: *
Allow: /
Disallow: /admin/

Sitemap: https://example.com/sitemap.xml
```

Without that file, robots.txt is generated from configuration, and those keys are per-domain
overridable like any other — as is the SEO fallback image:

```php
// config/domains/example_com.php
return [
    'multidomain-ghost.robots.disallow' => ['/cdn-cgi/', '/internal/'],
    'multidomain-ghost.robots.content_signal' => 'search=yes,ai-train=no',
    'multidomain-ghost.robots.sitemap' => 'https://example.com/sitemap.xml',
    'multidomain-ghost.seo.default_image' => 'https://cdn.example.net/example_com/social.png',
];
```

The rest of a domain's SEO needs no configuration: `app.name` and `app.url` come from the file
above, and `og:site_name`, `twitter:site` and the locale are read from the JSON in the description
of the domain's Ghost primary tag, so editors change them without a deploy.

> [!WARNING]
> **Ghost canonical URLs must match your routes.** Ghost's default permalink structure is `/{slug}/` at the root. If you use a prefix like `/blog/{slug}` or `/news/{slug}` in your Laravel routes, you must update the routing/canonical URLs in Ghost to match. The package finds posts by matching the current URL against the `canonical_url` returned by Ghost.

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

Ghost routes check this file-backed registry at request time. Removing a domain therefore makes any
route still held by a long-running process return 404.

### Re-running domain:add after a package upgrade

`domain:add` is idempotent, and re-running it is how a domain picks up scaffolding a newer version
of this package introduced — the `resources/domains/{domain_key}/` directory, for instance, or a
`storage/{domain_key}` subdirectory that did not exist before:

```bash
php artisan domain:add example.com   # backfill whatever is missing; touch nothing else
```

A file that is already present and already correct is not rewritten, so the output of a re-run is
the short list of what actually changed. What each flag may replace:

| | bare | `--force` | `--force-routes` |
| --- | --- | --- | --- |
| `storage/{key}/**`, `resources/domains/{key}/` | created if missing | ↑ | ↑ |
| `vite.config.js`, Herd conf entries | added if missing | ↑ | ↑ |
| `config/domains/{key}.php` | **never replaced** | **never replaced** | **never replaced** |
| `resources/views/{key}/*.blade.php` | kept | replaced | kept |
| `resources/css/{key}.css` | kept | replaced | kept |
| `routes/domains/{key}.php` | kept | **kept** | replaced, after confirming |

`--force` deliberately stops short of the route file: that file is where a domain's own routing
lives, and sweeping it away as a side effect of asking for fresh CSS is a trade nobody would agree
to if asked. Replacing it takes `--force-routes`, which prompts first when the file has been edited
since it was scaffolded, and warns rather than prompts under `--no-interaction`.

Anything replaced is copied to `{file}.{timestamp}.bak` beside itself first, so a flag that reached
further than you meant costs you a `mv`, not an afternoon. Add `*.bak` to `.gitignore` if you would
rather not see them.

Neither flag can touch `config/domains/{key}.php`. It holds a domain's identity — its URL, its name,
its cache prefix — and it is what `DomainRegistry` reads to decide the domain exists at all.

## Ghost API options

`config/multidomain-ghost.php` carries only the keys worth deciding per deployment, and each is
reachable from `.env`:

| Variable | Default | Description |
| --- | --- | --- |
| `GHOST_URL` | none | Ghost site or Content API base URL. |
| `GHOST_CONTENT_KEY` | none | Content API key. |
| `GHOST_ADMIN_URL` | none | Admin API base URL. |
| `GHOST_ADMIN_KEY` | none | Admin API key, `id:hex_secret`. |
| `GHOST_API_MODE` | `auto` | `auto`, `content` or `admin`. |
| `GHOST_API_VERSION` | `v6.0` | Ghost `Accept-Version` header. |
| `GHOST_TIMEOUT` | `10` | HTTP timeout in seconds. |
| `GHOST_CACHE_ENABLED` | on outside `local` | Opt into caching locally to reproduce bugs. |
| `GHOST_CACHE_STORE` | auto-provisioned | Store Ghost content lives in. |
| `GHOST_CACHE_TTL` | 30 days | Cached content lifetime, in seconds. |
| `GHOST_ROUTES_AUTO_REGISTER` | `true` | Register every registered domain's routes. |
| `GHOST_ROUTES_CATCH_ALL` | `false` | Resolve unmatched paths against Ghost. |
| `GHOST_WEBHOOK_SECRET` | none | HMAC secret shared with the Ghost webhook. |
| `GHOST_MAX_BLOG_PAGE` | `200` | Highest `?page=` served on blog and feed routes; past it, 404. |
| `GHOST_ROBOTS_CONTENT_SIGNAL` | none | `Content-Signal:` line in the generated robots.txt. |

`auto` uses Admin credentials only in the local environment when both are present; `content` and
`admin` select one explicitly. The Admin API is what makes drafts readable, so it is how an
unpublished post is previewed locally:

```dotenv
GHOST_ADMIN_URL=https://cms.example.com
GHOST_ADMIN_KEY=id:hex_secret
GHOST_API_MODE=auto
```

### Everything else

The keys below are **not** in the published file and have no environment variable. Each has a
default in the package; add the key to `config/multidomain-ghost.php` to override one.

| Key | Default | Description |
| --- | --- | --- |
| `jwt_audience` | `/admin/` | `aud` claim of the Admin API token. |
| `verify_ssl` | `true` | TLS certificate verification. |
| `retry_times` | `2` | HTTP retry count. |
| `retry_sleep` | `200` | Milliseconds between retries. |
| `cache.prefix` | `multidomain_ghost` | Prefix of the shared Ghost cache store. |
| `cache.miss_ttl` | `300` | Lifetime of a cached "not found" answer; `0` disables it. |
| `cache.empty_ttl` | `300` | Lifetime of a cached empty response. |
| `domain_tag_prefix` | `hash-` | Prefix of the Ghost tag each domain is filtered by. |
| `allow_unsigned_webhooks` | `false` | Accept webhooks with no signature. Development only. |
| `webhook_tolerance` | `300` | Seconds a webhook signature stays valid. |
| `routes.paths` | `/sitemap.xml`, `/robots.txt`, `/ads.txt` | Merged over the defaults, so one entry can be relocated or set to `null` on its own. |
| `routes.middleware` | `['web']` | Middleware wrapping every domain's routes. |
| `routes.redirect_www` | `true` | 301 `www.` to the bare domain. |
| `routes.webhook.enabled` | `true` | Register the webhook route. |
| `routes.webhook.uri` | `webhook/ghost/post` | Webhook path. |
| `routes.webhook.middleware` | `['throttle:500,1']` | Rate limit on the webhook route. |
| `views.page` / `views.blog` | `multidomain-ghost::page` / `::blog` | Fallback views. |
| `seo.default_image` | `https://{domain}/img/{domain_key}/apple-touch-icon.png` | Template for the fallback social image. |
| `robots.sitemap` | `https://{domain}/sitemap.xml` | `Sitemap:` line in the generated robots.txt. |
| `robots.disallow` | `['/cdn-cgi/']` | `Disallow:` lines in the generated robots.txt. |

`{domain}` and `{domain_key}` expand to the active hostname and its directory-safe form
(`example.com` / `example_com`). A per-domain override belongs in `config/domains/{domain_key}.php`
rather than here.

## Webhooks and cache invalidation

Point a Ghost webhook at `https://your-laravel-app.com/webhook/ghost/post`. The route is registered
outside the `web` middleware group, so no CSRF exception is needed; requests are verified with
`X-Ghost-Signature` and `GHOST_WEBHOOK_SECRET`. Unsigned webhooks are rejected unless
`allow_unsigned_webhooks` is set to `true` in the config file, which belongs only in a
controlled development environment.

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
    'ttl' => (int) env('GHOST_CACHE_TTL', 60 * 60 * 24 * 30),
],
```

`miss_ttl` (300s) remembers "this URL has no content" so an unknown URL cannot reach Ghost on every
request; `0` disables it. `empty_ttl` (300s) keeps successful-but-empty responses short-lived, so a
mistyped domain tag cannot freeze an empty sitemap for the full TTL. Neither is in the published
file — add the key to change one.

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
