# Laravel Multi-Domain Ghost

Headless Ghost CMS integration for Laravel applications that serve one or many domains.

## What the package includes

- Ghost Content API client, with optional Admin API reads for local development
- Request-scoped domain resolution and `hash-domain-com` Ghost tag filtering
- Per-domain content, blog, and sitemap caches
- Signed Ghost webhook endpoint and cache invalidation
- Reusable sitemap-link and feed-data builders with no imposed XML structure; sitemap links
  include both Ghost posts and Ghost pages
- A neutral fallback Blade view for Ghost content pages
- SEO data, a no-op domain enricher, and a no-op content transformer
- Extension contracts for application-specific enrichment and content transformations
- Optional Laravel foundation for per-domain storage, config overrides, bootstrap caches, and
  Artisan domain context
- Domain management commands that do not create per-domain environment files

The package auto-discovers its service provider. Publishing its config is optional.

## Install

```bash
composer require mr-sonj/laravel-multidomain-ghost
```

Only the Ghost site URL and Content API key are required:

```env
GHOST_URL=https://your-ghost-instance.com
GHOST_CONTENT_KEY=your-content-api-key
```

`GHOST_URL` may be the Ghost site URL, `/ghost/api`, or
`/ghost/api/content`; the package normalizes all three forms.

No entry in `config/services.php`, copied package config, webhook route, or CSRF exception is
required. The package can connect to Ghost and return content/system data with only these two
environment values.

## Optional full multi-domain foundation

Ghost content filtering works with Laravel's normal `Application`. To additionally isolate Laravel
storage and configuration per domain, opt into the package application in `bootstrap/app.php`:

```php
use MrSonj\MultiDomainGhost\Foundation\Application;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // normal middleware and exception configuration
    ->create();

return $app;
```

The package detects the HTTP host before Laravel loads configuration, selects
`storage/{domain_key}`, then applies `config/domains/{domain_key}.php` after the base config. This
ordering ensures logs, file cache, sessions, compiled views, and local filesystem roots all use
the selected domain storage.

```php
// config/domain.php
return [
    'domains' => [
        'example.com' => 'example.com',
    ],
];
```

```php
// config/domains/example_com.php
return [
    'app.name' => 'Example',
    'app.url' => 'https://example.com',
    'cache.prefix' => 'example_com_cache',
];
```

Domain override files are config, not env files. Shared secrets remain in the application's normal
environment file.

Commands:

```bash
php artisan domain:add example.com --no-interaction
php artisan domain:list
php artisan domain --domain=example.com
php artisan domain:remove example.com
```

Every Artisan command accepts `--domain`. For direct workers, run one process per domain:

```bash
php artisan queue:work --domain=example.com
```

If the app uses `queue:listen`, replace Laravel's queue provider so the listener forwards the
domain to its child workers:

```php
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Support\ServiceProvider;

'providers' => ServiceProvider::defaultProviders()->replace([
    QueueServiceProvider::class => MrSonj\MultiDomainGhost\Queue\QueueServiceProvider::class,
])->toArray(),
```

The full foundation is opt-in because a service provider alone runs too late to change storage
paths used while Laravel loads configuration.

## Content routes

The application still owns its public URLs. Point each Ghost-backed URL at the package
controller:

```php
use Illuminate\Support\Facades\Route;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;

Route::domain('example.com')->group(function () {
    Route::get('/', [GhostController::class, 'page']);

    Route::get('/about', [GhostController::class, 'page'])
        ->defaults('viewPath', 'example_com/about');
});
```

When `viewPath` is omitted, the package renders its complete
`multidomain-ghost::page` fallback. A custom view receives `$content` and `$seo`.

```blade
<title>{{ $seo['title'] }}</title>
<meta name="description" content="{{ $seo['description'] }}">

<h1>{{ $content['title'] }}</h1>
{!! $content['html'] !!}
```

## Application-owned system routes

The package does not register public robots, sitemap, or feed routes. Applications choose which
domains expose them and which URI, middleware, route name, and response format they use:

```php
use Illuminate\Support\Facades\Route;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;

Route::domain('example.com')->group(function () {
    Route::get('/robots.txt', [GhostController::class, 'robots']);
    Route::get('/sitemap.xml', [GhostController::class, 'sitemap']);
    Route::get('/feed', [GhostController::class, 'feed']);
});
```

When routed directly to the package controller, `sitemap()` returns JSON with a `links` array,
`feed()` returns JSON with `domain`, `dataBlog`, and `page`, and `robots()` returns plain text.
This lets the application choose XML Sitemap, sitemap index, RSS, Atom, JSON Feed, or another
structure.

For rendered XML/RSS, extend the package controller and route directly to the application
controller:

```php
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostController as PackageGhostController;

class GhostController extends PackageGhostController
{
    public function sitemap(): Response
    {
        return response()
            ->view('sitemap', ['links' => $this->sitemapLinks()])
            ->header('Content-Type', 'text/xml; charset=UTF-8');
    }

    public function feed(Request $request): Response
    {
        return response(view('feed', $this->feedData($request)))
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }
}
```

```php
use App\Http\Controllers\GhostController;
use Illuminate\Support\Facades\Route;

Route::domain('example.com')->group(function () {
    Route::get('/robots.txt', [GhostController::class, 'robots']);
    Route::get('/sitemap.xml', [GhostController::class, 'sitemap']);
    Route::get('/feed', [GhostController::class, 'feed']);
});
```

No package config file or controller binding is required for this override.

## Route supplied automatically

Only the shared signed webhook route is registered automatically:

```text
POST /webhook/ghost/post
```

It can be disabled without publishing config:

```env
GHOST_WEBHOOK_ROUTE_ENABLED=false
```

## Ghost webhook

Create the webhook in Ghost Admin with this target:

```text
https://your-laravel-app.com/webhook/ghost/post
```

Use the same secret in Ghost Admin and Laravel:

```env
GHOST_WEBHOOK_SECRET=your-webhook-secret
```

The package validates Ghost's `X-Ghost-Signature` HMAC and timestamp. Unsigned webhooks are
rejected by default. For an isolated local-only setup, they can be enabled explicitly with
`GHOST_ALLOW_UNSIGNED_WEBHOOKS=true`.

The route accepts both `post` and `page` webhook payloads, invalidates the affected
domain caches, and dispatches `GhostPostUpdated`.

## Optional local Admin API

The default `GHOST_API_MODE=auto` uses the Content API everywhere, except in the local
environment when both Admin API values are present:

```env
GHOST_ADMIN_URL=https://your-ghost-instance.com
GHOST_ADMIN_KEY=your-admin-api-id:your-admin-api-secret
```

Force a mode when needed:

```env
GHOST_API_MODE=content
# or
GHOST_API_MODE=admin
```

TLS verification stays enabled by default. Only self-signed local Ghost installations
should set `GHOST_VERIFY_SSL=false`.

## Multi-domain Ghost content

Every Ghost post/page needs:

1. A `canonical_url` matching the Laravel URL.
2. A private domain tag such as `#example-com`.
3. A content-type tag such as `#page` or `#product`.

| Laravel key | Ghost tag |
| --- | --- |
| `example_com` | `#example-com` / `hash-example-com` |

The Laravel folder key uses underscores; the Ghost tag always uses hyphens.

## Domain enrichment

The built-in no-op enricher means this binding is optional. Bind the contract only when
a domain needs extra data:

```php
use MrSonj\MultiDomainGhost\Contracts\DomainEnricherInterface;
use MrSonj\MultiDomainGhost\Services\DomainResolver;
use MrSonj\MultiDomainGhost\Support\NullEnricher;

$this->app->bind(DomainEnricherInterface::class, function ($app) {
    $domain = $app->make(DomainResolver::class)->resolve();

    return match ($domain) {
        'example.com' => $app->make(ExampleEnricher::class),
        default => new NullEnricher,
    };
});
```

## Application-specific content transformations

Core normalization does not inject Alpine directives, strip brand referral parameters,
or rewrite titles. Applications that need those mutations may bind
`ContentTransformerInterface`; the built-in transformer is a no-op.

## Optional settings

```env
GHOST_API_VERSION=v6.0
GHOST_CACHE_TTL=2592000
GHOST_DOMAIN_TAG_PREFIX=hash-
GHOST_REGISTERED_DOMAINS=example.com,test.com
GHOST_WEBHOOK_TOLERANCE=300
GHOST_TIMEOUT=10
GHOST_RETRY_TIMES=2
GHOST_RETRY_SLEEP=200
```

To override advanced arrays, publish the config:

```bash
php artisan vendor:publish --tag=multidomain-ghost-config
```

## Documentation & Features

For a detailed feature inventory, source code mappings, verification checklists, and scope boundaries, see [FEATURES.md](FEATURES.md).

## Development

```bash
composer test
composer lint
```

## License

MIT
