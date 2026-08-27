# Changelog

All notable changes to this project are documented in this file and published automatically to [GitHub Releases](https://github.com/mr-sonj/laravel-multidomain-ghost/releases).

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Breaking

- A domain's `robots.txt`, `ads.txt`, `llms.txt` and `llms-full.txt` moved from `resources/domains/{domain_key}/` to that domain's own view folder, `resources/views/{domain_key}/`. A domain has one folder under `resources/` again instead of two, beside its Blade files. Move the files across — `mv resources/domains/{key}/* resources/views/{key}/` for each domain, then delete `resources/domains/`. There is no fallback to the old location: a file left behind simply stops being served, and `/ads.txt`, `/llms.txt` and `/llms-full.txt` lose their routes. `domain:add` no longer creates `resources/domains/{key}/`.

### Added

- `llms.txt` and `llms-full.txt` join `ads.txt` under `resources/views/{domain_key}/`. Each is served verbatim and gets its route only on the domains that own the file, and each is independent of the other. `routes.paths` gained `llms` (`/llms.txt`) and `llms_full` (`/llms-full.txt`); set either to `null` to leave it unregistered.
- `domain:add --force-routes` replaces `routes/domains/{domain_key}.php`, which `--force` no longer does. It asks for confirmation when the file has been edited since it was scaffolded, and warns instead of asking under `--no-interaction`.
- `domain:add` now copies any file it is about to replace to `{file}.{timestamp}.bak` beside itself.

### Fixed

- `domain:add --force` overwrote `routes/domains/{domain_key}.php` even though the option documented itself as covering views and CSS only, so anyone who ran it to refresh a stale CSS stub silently lost every route they had added for that domain. `--force` now leaves the route file alone; use `--force-routes` to replace it deliberately.

### Changed

- `domain:add` no longer rewrites a scaffolded file whose contents already match what it would write. Re-running it after a package upgrade — the supported way to pick up newly introduced scaffolding — now reports only what actually changed, and leaves no backups of files nobody touched.

## 1.2.4 - 2026-08-26

### Breaking

- Content routes (`/`, `/blog`, `/blog/{slug}`, `/feed`) are no longer auto-registered for every domain. They must now be declared in `routes/domains/{domain_key}.php`. Run `php artisan domain:add {domain}` to generate the missing file for each domain.
- `routes.paths.ads = null` in configuration now completely disables the `ads.txt` route instead of falling back to `/ads.txt`. If you had it set to `null` to keep the route, change it to `'/ads.txt'`.
- `GhostRouteRegistrar::registerAll()` no longer registers the catch-all route. If calling manually, you must also call `GhostRouteRegistrar::registerCatchAlls()` during the `booted` phase.
- `ads.txt` is now read from `resources/domains/{domain_key}/ads.txt` only. The `multidomain-ghost.ads.txt` config value (`GHOST_ADS_TXT`) and the legacy `services.adsense.ads_txt` fallback are gone, and `/ads.txt` is registered only for domains that own the file. Move each domain's content into its own file, or the route returns 404.
- `multidomain-ghost.robots.content_signal` now reads `GHOST_ROBOTS_CONTENT_SIGNAL` instead of `ROBOTS_CONTENT_SIGNAL`. Rename the variable in `.env` or the `Content-Signal:` line disappears.
- `config/multidomain-ghost.php` was trimmed from 39 settings to 16 — only the ones worth deciding per deployment. Every key it drops keeps its default inside the package, so behaviour is unchanged, but **these environment variables no longer do anything**: `GHOST_JWT_AUDIENCE`, `GHOST_VERIFY_SSL`, `GHOST_RETRY_TIMES`, `GHOST_RETRY_SLEEP`, `GHOST_CACHE_PREFIX`, `GHOST_CACHE_MISS_TTL`, `GHOST_CACHE_EMPTY_TTL`, `GHOST_DOMAIN_TAG_PREFIX`, `GHOST_ALLOW_UNSIGNED_WEBHOOKS`, `GHOST_WEBHOOK_TOLERANCE`, `GHOST_ROUTES_REDIRECT_WWW`, `GHOST_WEBHOOK_ROUTE_ENABLED`, `GHOST_WEBHOOK_ROUTE`, `GHOST_SEO_DEFAULT_IMAGE`, `GHOST_ROBOTS_SITEMAP`. If you set one, add the corresponding key back to `config/multidomain-ghost.php` — the README lists every key with its default. An existing published config keeps working untouched.

### Fixed

- The Admin API token's `aud` claim fell back to `/ghost/api/admin/` in code while the published config said `/admin/`. Ghost v5+ expects `/admin/`, so any application that had removed the key from its config was signing tokens Ghost rejects. The code default is now `/admin/`.
- The webhook route's middleware fell back to `[]` in code while the published config said `['throttle:500,1']`, so an application without the config block ran an unauthenticated, unthrottled endpoint. The rate limit is now the code default.

### Added

- `resources/domains/{domain_key}/` for a domain's own static files, alongside `config/domains/` and `routes/domains/`. A `robots.txt` there replaces the generated policy in full, `Sitemap:` line included; an `ads.txt` there is served verbatim. `php artisan domain:add` creates the directory.
- `MrSonj\MultiDomainGhost\Support\DomainAssets` for reading those files.

### Changed

- `multidomain-ghost.routes.paths` is now merged over the package defaults instead of replacing them. Relocating or disabling one of the three standard files no longer requires restating the other two.

### Removed

- Removed `home`, `blog`, `post`, and `feed` keys from `multidomain-ghost.routes.paths` global config map.
- Removed private method `GhostRouteRegistrar::moveCatchAllLast()`.
- Removed the `ads` block from `config/multidomain-ghost.php`.
- Removed the private method `GhostRouteRegistrar::adsTxtContent()`.

## 1.2.0 - 2026-08-26

### Fixed

- Consolidated domain registration from 4 fragmented sources down to 1 single source of truth:
  the presence of `config/domains/{key}.php`.
- Eliminated the silent bug where `GHOST_REGISTERED_DOMAINS` shadowed all domains in `config/domain.php`.
- `seoData()` read `$content['domain']` without a guard, so building the array by
  hand produced a warning and an `is_part_of` of `https:///#website`.

### Changed

- `DomainRegistry::all()` now discovers registered domains directly from `config/domains/*.php`,
  so stale Laravel config caches cannot hide a newly added domain or retain a removed one.
- Ghost routes reject hosts which no longer have a domain config file, and domain add/remove clear
  stale per-domain config, route and event cache files.
- Ghost webhooks now fail closed when the registry is empty and validate a canonical URL's domain
  before purging any cache keys.
- Removed `config/domain.php` generation and `GHOST_REGISTERED_DOMAINS` env option.
- `domain:add` and `domain:remove` now manage `config/domains/{key}.php` directly without dynamic `var_export`.
- Ghost caching is now `multidomain-ghost.cache.enabled` (`GHOST_CACHE_ENABLED`)
  instead of a hard-coded `local` environment check. The default is unchanged:
  off in local, on everywhere else.
- Webhooks are handled by `GhostWebhookController`, which does not construct the
  domain enricher. `GhostController::postWebhook()` still delegates to it.
- The shipped config no longer declares the duplicated top-level `cache_ttl`.
  Published config files that still carry it keep working.

### Deprecated

- `GhostController::postWebhook()` is deprecated. Route webhooks at
  `GhostWebhookController` instead.
- `DomainResolver::domainTagSlug()`, `::normalizeDomain()`, `::dirKeyFor()` and
  `GhostClient::domainTagSlug()`. Each is now a one-line delegate to the new
  `Domain` value object — `Domain::make($host)->tag()`, `->host()`, `->key()`.
  They keep working and will be removed in the next major.

## 1.1.0 - 2026-08-25

Ghost content caching moved to a dedicated store, and upstream failures no longer
reach the browser. No public API was removed; existing applications keep working
without changes.

### Fixed

- **Cross-domain cache invalidation never took effect.** `GhostCacheManager` swapped
  `config('cache.prefix')` before purging, but Laravel memoizes a cache store the first
  time it is resolved - and route middleware such as `throttle` resolves it before any
  controller runs. Purges therefore landed under the prefix of whichever domain the
  webhook arrived on. Ghost content now lives in one dedicated store shared by every
  domain, so invalidation no longer depends on the active domain.
- **A Ghost outage returned HTTP 500 for every domain.** `Http::retry()` throws by
  default once its attempts are exhausted, which made the client's own error handling
  and its `posts` → `pages` fallback unreachable. Failures are now logged and turned
  into "no content".
- **Unknown URLs reached Ghost on every request.** `Cache::remember()` cannot store a
  null, so each miss re-ran the lookup - up to four upstream calls per request on a
  wildcard route. Misses are now remembered for `cache.miss_ttl`.
- **`?page=` was unbounded** on blog and feed routes, so each distinct value minted a
  cache entry and a Ghost request. Anything past `max_blog_page` now returns 404 rather
  than the last page's listing, which would publish one listing under unlimited URLs. A
  junk or negative value is still served as page 1.
- **Custom JSON-LD on the primary domain tag was not detected.** The `schema` flag was
  computed before the tag's code injection was merged in, so views emitted a second,
  conflicting JSON-LD block.
- **An empty response was cached for the full TTL.** A mistyped domain tag or a revoked
  key could freeze an empty sitemap for a month. Empty results now use `cache.empty_ttl`.
- **`DomainName::normalize()` passed unusable hosts through verbatim**, letting a
  malformed `Host` header reach cache keys and storage paths. Invalid hosts now
  normalize to an empty string and are ignored.
- **`DomainResolver` preferred the raw `$_SERVER['HTTP_HOST']`** over the validated
  request host. The request host now wins, so trusted-proxy and trusted-host handling
  applies. The application's own domain, when the multi-domain `Application` is in use,
  stays authoritative.
- **Enricher auto-discovery probed illegal class names** for domains starting with a
  digit or containing a hyphen, silently falling back to `NullEnricher`. Such domains
  are now recognised as unmappable by convention and must be listed in
  `multidomain-ghost.enrichers`; `domain:list` shows what each domain resolves to.
- The scaffold generated by `domain:add` now redirects `www.` to the apex domain
  instead of leaving it unrouted.

### Added

- `php artisan domain:optimize` builds (or clears) config, route and event caches for
  every registered domain. A plain `config:cache` writes a file no domain request reads.
- `domain:list` reports the effective cache prefix and resolved enricher per domain, and
  warns when two domains share a cache prefix, or when a domain overrides `cache.default`
  - which would give it a Ghost cache store of its own and silently exclude it from
  webhook invalidation.
- The Ghost cache store is declared in `cache.stores` at boot, so
  `php artisan cache:clear multidomain-ghost` addresses it. Set `cache.store` to a store
  you declared yourself in `config/cache.php` to own that decision outright.
- Configurable `seo.default_image`, `robots.sitemap`, `robots.disallow` and `ads.txt`,
  so the package no longer hard-codes one application's asset layout. Defaults reproduce
  the previous behaviour exactly.
- RSS feeds now declare `xmlns:atom`, `<atom:link rel="self">`, `<language>` and
  `<lastBuildDate>`.
- camelCase `modContent()`, `findPrimaryTag()` and `urlToPath()` on `GhostClient`. The
  snake_case names still work and are marked deprecated.
- A GitHub Actions matrix covering PHP 8.3/8.4 against Laravel 11, 12 and 13, plus a
  `phpunit.xml` and a Pint check.

### Upgrade notes

- **Cached Ghost content is re-fetched once.** Entries move from each domain's cache
  prefix to the shared `multidomain_ghost` prefix, so the first request per URL after
  deploying repopulates the cache. Old entries expire on their own; run
  `php artisan cache:clear` if you would rather not wait.
- **Add `domain:optimize` to your deploy script** in place of `config:cache` /
  `route:cache`.
- Run `php artisan domain:list` once after upgrading. If it warns that two domains share
  a cache prefix, set `'cache.prefix'` in each `config/domains/{key}.php` - they are
  otherwise sharing a cache and, on a cache-backed session driver, a session namespace.
- Republishing the config is optional. Every new key falls back to its previous
  behaviour, including when an older published file replaces the `robots` block.
