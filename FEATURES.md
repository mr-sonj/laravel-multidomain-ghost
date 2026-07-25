# Feature Inventory — `mr-sonj/laravel-multidomain-ghost`

This document serves as a verification checklist for the source package. It describes what the package provides at the current commit, the source files responsible for each feature, associated tests, and the scope intentionally left for the application to manage.

## 1. Package Scope

The package combines two functional feature sets:

1. Headless Ghost CMS integration for one or multiple domains.
2. Optional multi-domain foundation for Laravel: early domain detection, isolated storage/config/cache, and passing domain context into Artisan and queues.

The package supports:

- PHP `^8.3|^8.4`.
- Laravel/Illuminate `^11|^12|^13`.
- Ghost Content API.
- Ghost Admin API for read/preview when configured.

Primary Sources:

- [`composer.json`](composer.json)
- [`src/MultiDomainGhostServiceProvider.php`](src/MultiDomainGhostServiceProvider.php)
- [`config/multidomain-ghost.php`](config/multidomain-ghost.php)

## 2. Laravel Package Integration

### 2.1 Auto-discovery

- Automatically registers `MultiDomainGhostServiceProvider`.
- Automatically registers the facade alias `Ghost`.
- Does not require consumers to declare the provider manually.

Source:

- [`composer.json`](composer.json)
- [`src/MultiDomainGhostServiceProvider.php`](src/MultiDomainGhostServiceProvider.php)
- [`src/Facades/Ghost.php`](src/Facades/Ghost.php)

### 2.2 Config

- Merges default configuration without requiring publishing.
- Can be published via the `multidomain-ghost-config` tag.
- Supports URL/key, API mode/version, SSL, timeout/retry, cache TTL, webhook, domain allowlist, route webhook, view fallback, robots, enrichers, and transformers.

Source:

- [`config/multidomain-ghost.php`](config/multidomain-ghost.php)
- [`src/MultiDomainGhostServiceProvider.php`](src/MultiDomainGhostServiceProvider.php)

## 3. Domain Resolution

### 3.1 Domain Sources

`DomainResolver` resolves the domain in the following order:

1. Domain explicitly set via `setDomain()`.
2. Domain from package `Application`, if consumer opts in to full foundation.
3. `--domain` CLI option or web server globals.
4. Laravel `Request::getHost()`.
5. `HTTP_HOST` / `SERVER_NAME`, including localhost.
6. Host from `config('app.url')`, defaulting to `localhost`.

### 3.2 Name Normalization

- Lowercase domain.
- Strip scheme, port, path, and trailing dots.
- Laravel directory key: `example.com` → `example_com`.
- Ghost private-tag slug: `example.com` → `hash-example-com`.
- Ghost filter: `tag:hash-example-com`.
- Validates domain when running add/remove commands.

Source:

- [`src/Services/DomainResolver.php`](src/Services/DomainResolver.php)
- [`src/Support/DomainName.php`](src/Support/DomainName.php)

Tests:

- [`tests/Unit/DomainResolverTest.php`](tests/Unit/DomainResolverTest.php)
- [`tests/Unit/DomainFoundationTest.php`](tests/Unit/DomainFoundationTest.php)

## 4. Optional Multi-Domain Laravel Foundation

This feature is active only when consumer uses:

```php
use MrSonj\MultiDomainGhost\Foundation\Application;
```

### 4.1 Early Domain Detection

- Detects domain before `LoadEnvironmentVariables` and `LoadConfiguration`.
- Supports both HTTP kernel and Console kernel.
- Provides `app()->domain()`.
- Supports `app()->domain('example.com', 'other.com')` to check current active domain.

### 4.2 Per-Domain Storage

If `storage/{domain_com}` exists:

- `storage_path()` points to the domain's storage directory.
- Logs, file cache, sessions, compiled views, and local filesystem use domain storage.

If the directory does not exist, the package preserves the shared `storage/` instead of failing.

### 4.3 Per-Domain Config Overrides

After Laravel loads base configuration, the package loads:

```text
config/domains/{domain_com}.php
```

The file returns a flat array with dot notation keys:

```php
return [
    'app.name' => 'Example',
    'app.url' => 'https://example.com',
    'cache.prefix' => 'example_com_cache',
];
```

### 4.4 Per-Domain Bootstrap Caches

When domain storage exists, the package isolates cache file paths:

- `bootstrap/cache/config-{domain_com}.php`
- Route cache with suffix `-{domain_com}.php`
- `bootstrap/cache/events-{domain_com}.php`

Source:

- [`src/Foundation/Application.php`](src/Foundation/Application.php)
- [`src/Foundation/Bootstrap/DetectDomain.php`](src/Foundation/Bootstrap/DetectDomain.php)
- [`src/Foundation/Bootstrap/LoadDomainConfiguration.php`](src/Foundation/Bootstrap/LoadDomainConfiguration.php)
- [`src/Foundation/Http/Kernel.php`](src/Foundation/Http/Kernel.php)
- [`src/Foundation/Console/Kernel.php`](src/Foundation/Console/Kernel.php)
- [`src/Foundation/Configuration/ApplicationBuilder.php`](src/Foundation/Configuration/ApplicationBuilder.php)

Tests:

- [`tests/Unit/DomainFoundationTest.php`](tests/Unit/DomainFoundationTest.php)

## 5. Artisan Domain Context and Commands

### 5.1 Global `--domain`

When using the package foundation, every Artisan command receives a global option:

```bash
php artisan optimize --domain=example.com
php artisan queue:work --domain=example.com
php artisan schedule:run --domain=example.com
```

### 5.2 Domain Commands

| Command | Feature |
| --- | --- |
| `domain --domain=example.com` | Prints active domain |
| `domain:list` | Lists domains, Laravel key, Ghost tag slug, storage/config status |
| `domain:add example.com` | Registers domain and scaffolds storage/config/view/CSS |
| `domain:remove example.com` | Unregisters domain, preserves config and storage by default |
| `domain:remove example.com --force` | Unregisters and deletes domain storage directory |
| `ghost:domain-add` | Alias for `domain:add` |
| `ghost:domain-list` | Alias for `domain:list` |

`domain:add` creates:

- Required storage subdirectories for Laravel.
- `config/domains/{domain_com}.php`.
- `resources/views/{domain_com}`.
- `resources/css/{domain_com}.css`.
- Entry in `config/domain.php`.

The command does not create `.env.{domain}` files.

Source:

- [`src/Foundation/Console/Application.php`](src/Foundation/Console/Application.php)
- [`src/Console/Commands/DomainCurrentCommand.php`](src/Console/Commands/DomainCurrentCommand.php)
- [`src/Console/Commands/GhostDomainListCommand.php`](src/Console/Commands/GhostDomainListCommand.php)
- [`src/Console/Commands/GhostDomainAddCommand.php`](src/Console/Commands/GhostDomainAddCommand.php)
- [`src/Console/Commands/DomainRemoveCommand.php`](src/Console/Commands/DomainRemoveCommand.php)

## 6. Queue Domain Propagation

### 6.1 Direct Worker

Supports:

```bash
php artisan queue:work --domain=example.com
```

### 6.2 `queue:listen`

Custom queue provider:

- Captures `--domain` from `queue:listen`.
- Passes this option down to child `queue:work` processes.
- Preserves standard Laravel listener options.

Consumers must replace Laravel's `QueueServiceProvider` with the package provider in `config/app.php` if using `queue:listen`.

Source:

- [`src/Queue/QueueServiceProvider.php`](src/Queue/QueueServiceProvider.php)
- [`src/Queue/Console/ListenCommand.php`](src/Queue/Console/ListenCommand.php)
- [`src/Queue/Listener.php`](src/Queue/Listener.php)
- [`src/Queue/ListenerOptions.php`](src/Queue/ListenerOptions.php)

## 7. Ghost API Client

### 7.1 Content API

- Minimal/default mode.
- Requires only `GHOST_URL` and `GHOST_CONTENT_KEY`.
- Automatically attaches domain tag filter to every list/content request.
- Allows additional filters, fields, page, limit, and includes.
- Supports fetching posts, pages, slugs, and content by canonical URL.
- When searching content, tries posts endpoint first, then pages endpoint.
- Sitemap source combines both posts and pages, deduplicating canonical URLs.

### 7.2 Admin API

- Generates Ghost Admin JWT using `firebase/php-jwt`.
- `auto` mode: uses Admin API only when local environment and Admin URL/key are present.
- Can explicitly force `content` or `admin` mode.
- Admin API requests include `formats=html`.

### 7.3 Endpoint Normalization

Accepts URLs in forms of:

- `https://ghost.example.com`
- `https://ghost.example.com/ghost/api`
- `https://ghost.example.com/ghost/api/content`
- Legacy versioned API URLs.

Package automatically generates appropriate posts/pages endpoints for Content or Admin API.

### 7.4 HTTP Controls

- `Accept-Version`.
- Timeout.
- Retry count and retry sleep.
- SSL verification enabled by default.
- Validates required URL/key before sending HTTP requests.

### 7.5 Core Content Normalization

After receiving content:

- Adds `domain`.
- Maps `canonical_url` to `url`.
- Generates `path`.
- Detects custom JSON-LD schema in `codeinjection_head`.
- Finds primary domain tag.
- Merges `codeinjection_head` and `codeinjection_foot` from primary domain tag.
- Executes application content transformer after core normalization.

Core does not inject Alpine directives, rewrite titles, or strip brand referral parameters.

Source:

- [`src/Client/GhostClient.php`](src/Client/GhostClient.php)
- [`src/Facades/Ghost.php`](src/Facades/Ghost.php)

Tests:

- [`tests/Unit/GhostClientTest.php`](tests/Unit/GhostClientTest.php)

## 8. Ghost Content Service and Cache

### 8.1 Content Lookup

- Retrieves post/page by canonical URL.
- Automatically tries both URL variants (with and without trailing slash).
- Caches alias so subsequent lookups do not repeat variant request.

### 8.2 Blog Data

- Pagination and configurable limit.
- Automatically filters out content tagged with `#page`.
- Includes tags and authors.

### 8.3 Sitemap/Slugs

- Caches canonical URL list for sitemap generation.

### 8.4 Cache Policy

- Local environment: bypasses Ghost content cache.
- Non-local: uses `GHOST_CACHE_TTL`.
- Cache keys contain domain.
- Blog cache uses a generation key to invalidate pagination without scanning Redis keys.

Source:

- [`src/Services/GhostContentService.php`](src/Services/GhostContentService.php)
- [`src/Services/GhostCacheManager.php`](src/Services/GhostCacheManager.php)

Tests:

- [`tests/Unit/GhostContentServiceTest.php`](tests/Unit/GhostContentServiceTest.php)

## 9. Ghost Controller

### 9.1 Page/Content

- Constructs canonical URL from request host and path.
- Fetches Ghost content or returns 404 abort.
- Runs domain enricher.
- Builds SEO data.
- Renders route `viewPath` or fallback `multidomain-ghost::page`.
- Custom view receives `$content` and `$seo`.

### 9.2 SEO Data Builder

Returns neutral data array for consumer application to render:

- Title, description, canonical URL, and image.
- OpenGraph.
- Twitter Card.
- JSON-LD fallback.
- Metadata pulled from content first, falling back to primary domain tag.
- Does not force consumers into a specific SEO package.

### 9.3 System Data

- `robots()` returns plain text.
- `ads()` returns plain text from app config.
- `sitemapLinks()` returns normalized indexable links.
- `sitemap()` returns JSON by default.
- `feedData()` returns domain/blog/page data.
- `feed()` returns JSON by default.

Applications can extend the controller to render XML Sitemap, RSS, or Atom.

Source:

- [`src/Http/Controllers/GhostController.php`](src/Http/Controllers/GhostController.php)
- [`resources/views/page.blade.php`](resources/views/page.blade.php)

Tests:

- [`tests/Feature/GhostControllerTest.php`](tests/Feature/GhostControllerTest.php)

## 10. Signed Ghost Webhook

Package automatically registers a single public route:

```text
POST /webhook/ghost/post
```

### 10.1 Route Controls

- Can be enabled or disabled.
- URI can be customized.
- Middleware can be customized.
- Default throttle `500,1`.
- Route is outside `web` middleware group, so consumer needs no CSRF exception.

### 10.2 Signature Security

- Rejects unsigned webhooks by default.
- Validates `X-Ghost-Signature`.
- HMAC SHA-256 over raw body and timestamp.
- Timing-safe hash comparison.
- Accepts timestamp in seconds or milliseconds.
- Rejects timestamp outside configurable tolerance.
- Unsigned webhooks allowed only with explicit opt-in.

### 10.3 Payload and Invalidation

- Supports both `post` and `page` payloads.
- Handles both `current` and `previous` content states.
- Invalidates canonical URL cache and trailing-slash variant.
- Invalidates sitemap/slugs cache.
- Rotates blog cache generation key for posts.
- Enforces domain allowlist from package config or `config/domain.php`.
- Dispatches `GhostPostUpdated` event.

Event contains:

- Webhook name.
- Content type.
- List of cleared cache keys.
- List of affected domains.

Source:

- [`src/Http/Controllers/GhostController.php`](src/Http/Controllers/GhostController.php)
- [`src/Events/GhostPostUpdated.php`](src/Events/GhostPostUpdated.php)
- [`src/Services/GhostCacheManager.php`](src/Services/GhostCacheManager.php)

Tests:

- [`tests/Feature/GhostControllerTest.php`](tests/Feature/GhostControllerTest.php)

## 11. Extension Points

### 11.1 Domain Enricher

Contract:

```php
DomainEnricherInterface::enrich(array $content, string $canonicalUrl): array
```

Resolution:

1. Class mapped in `multidomain-ghost.enrichers`.
2. Convention `App\Services\{domain_com}\{StudlyDomainCom}Enricher`.
3. `NullEnricher`.

### 11.2 Content Transformer

Contract:

```php
ContentTransformerInterface::transform(array $content, string $domain): array
```

Resolution:

1. Class mapped in `multidomain-ghost.transformer`.
2. Convention `App\Services\GhostContentTransformer`.
3. `NullContentTransformer`.

`DomainResolver`, `GhostClient`, `GhostContentService`, and `GhostCacheManager` are scoped services.
The two extension contracts are bound via package service provider with default no-op implementations.

Source:

- [`src/Contracts/DomainEnricherInterface.php`](src/Contracts/DomainEnricherInterface.php)
- [`src/Contracts/ContentTransformerInterface.php`](src/Contracts/ContentTransformerInterface.php)
- [`src/Support/NullEnricher.php`](src/Support/NullEnricher.php)
- [`src/Support/NullContentTransformer.php`](src/Support/NullContentTransformer.php)
- [`src/MultiDomainGhostServiceProvider.php`](src/MultiDomainGhostServiceProvider.php)

Tests:

- [`tests/Unit/ServiceProviderTest.php`](tests/Unit/ServiceProviderTest.php)
- [`tests/Unit/GhostClientTest.php`](tests/Unit/GhostClientTest.php)

## 12. Scope Intentionally Left Out of Package

The following responsibilities belong to the consumer application or deployment environment:

- Creating or selecting `.env.{domain}` files.
- Storing application secrets.
- Registering public page routes for individual domains.
- Automatically registering robots/sitemap/feed routes.
- Imposing XML Sitemap, RSS, or Atom Blade structures.
- Dependency on `artesaos/seotools` or specific SEO renderers.
- Domain-specific business logic.
- Brand-specific HTML or title transformations.
- Updating `vite.config.js`.
- Configuring Nginx, DNS, TLS, Laravel Herd, or Forge.
- Generating production queue workers or scheduler services.
- Running database migrations.
- Replacing Ghost Admin or Ghost authoring workflows.

## 13. Verification Checklist

### Package Checks

```bash
composer validate --strict --no-check-publish
composer test
composer lint
```

Current Expectations:

- 25 tests pass.
- 62 assertions pass.
- Pint passes.

### Consumer Integration Checks

```bash
php artisan domain:list
php artisan domain --domain=example.com
php artisan route:list
php artisan optimize --domain=example.com
php artisan queue:work --help
```

Manual Checks:

- `app()->domain()` correct for HTTP and CLI.
- `storage_path()` points correctly to `storage/{domain_com}`.
- `config/domains/{domain_com}.php` overrides base configuration correctly.
- Package registers only the webhook route automatically.
- Page routes have real `viewPath` or use fallback view.
- Ghost request always includes `tag:hash-{domain-com}`.
- Content lookup works for both post/page and trailing-slash variants.
- Webhook invalid signature returns 403.
- Valid webhook clears expected caches and dispatches event.
- `queue:listen --domain=X` passes `--domain=X` down to child `queue:work`.

## 14. Release and Distribution Checklist

Before consumer deployment:

- Package source must be clean, committed, and pushed.
- Create a version tag instead of long-term dependency on `dev-main`.
- Consumer `composer.lock` must reference exact package commit or tag.
- Production installs package via Packagist, private Composer registry, VCS, or path repository as declared in `composer.json`.
- Composer credentials for private repository configured outside source control.
- Deployment clears and builds bootstrap cache per domain when using per-domain cache paths.
- Domain queue workers and scheduler execute with `--domain`.
