<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use MrSonj\MultiDomainGhost\Support\Domain;
use MrSonj\MultiDomainGhost\Support\DomainEnricherLocator;
use MrSonj\MultiDomainGhost\Support\DomainName;
use MrSonj\MultiDomainGhost\Support\DomainRegistry;

class GhostDomainListCommand extends Command
{
    protected $signature = 'domain:list';

    protected $aliases = ['ghost:domain-list'];

    protected $description = 'List all configured multi-domain sites and their storage/config status';

    public function handle(): int
    {
        $domains = DomainRegistry::all();

        if ($domains === []) {
            $this->warn('No domains registered in config/domains/*.php.');

            return self::SUCCESS;
        }

        $rows = [];
        $prefixes = [];
        $stores = [];

        foreach ($domains as $domain) {
            $name = Domain::make($domain);
            $sanitized = $name->key();
            $overrides = $this->overridesFor($sanitized);
            $cachePrefix = $this->cachePrefixFor($overrides);
            $prefixes[] = $cachePrefix;
            $stores[$domain] = $this->cacheStoreFor($overrides);

            $rows[] = [
                $domain,
                $sanitized,
                $name->tag(),
                is_dir(base_path("storage/{$sanitized}")) ? 'Yes' : 'No',
                file_exists(config_path("domains/{$sanitized}.php")) ? 'Yes' : 'No',
                $cachePrefix,
                DomainEnricherLocator::resolveClass($domain) ?? 'none',
            ];
        }

        $this->table(
            ['Domain', 'Sanitized Key', 'Ghost Tag', 'Storage Dir', 'Config Override', 'Cache Prefix', 'Enricher'],
            $rows,
        );

        $this->warnAboutSharedCachePrefixes($prefixes);
        $this->warnAboutDivergingCacheStores($stores);
        $this->warnAboutMissingViews($domains);

        return self::SUCCESS;
    }

    /**
     * The cache prefix a request for this domain actually runs under: its own
     * config/domains override when present, otherwise the application default.
     */
    private function cachePrefixFor(array $overrides): string
    {
        foreach (['cache.prefix', 'cache_prefix'] as $key) {
            if (filled($overrides[$key] ?? null)) {
                return (string) $overrides[$key];
            }
        }

        return (string) config('cache.prefix', '');
    }

    /**
     * The default cache store a request for this domain runs under. Domain
     * overrides are applied before service providers register, so this is also
     * the store the Ghost cache would be derived from for that domain.
     */
    private function cacheStoreFor(array $overrides): string
    {
        return filled($overrides['cache.default'] ?? null)
            ? (string) $overrides['cache.default']
            : (string) config('cache.default', '');
    }

    private function overridesFor(string $sanitized): array
    {
        $overrides = require config_path("domains/{$sanitized}.php");

        return is_array($overrides) ? $overrides : [];
    }

    /**
     * A route's `viewPath` default names a Blade file the application owns, and
     * nothing else checks that the file is there: the routes list, the tests and
     * this table are all green while it is missing. The request that finds out is
     * a public one, the first time Ghost has content for that route.
     *
     * The controller degrades to the package's own view rather than throwing, so
     * this is where the mistake is meant to surface - before a deploy, not after.
     *
     * @param  array<int, string>  $domains
     */
    private function warnAboutMissingViews(array $domains): void
    {
        $registered = array_flip($domains);
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $viewPath = $route->defaults['viewPath'] ?? null;

            if (! is_string($viewPath) || trim($viewPath) === '') {
                continue;
            }

            $domain = DomainName::normalize((string) $route->getDomain());

            if (! isset($registered[$domain]) || isset($missing[$domain][$viewPath])) {
                continue;
            }

            if (View::exists($viewPath)) {
                continue;
            }

            $missing[$domain][$viewPath] = $route->getName() ?: $route->uri();
        }

        foreach ($missing as $domain => $views) {
            foreach ($views as $viewPath => $route) {
                $hint = str_contains($viewPath, '::')
                    ? 'Register the namespace, or point the route at a view that exists.'
                    : 'Create resources/views/'.str_replace('.', '/', $viewPath).'.blade.php, or point the route at a view that exists.';

                $this->warn("Domain [{$domain}] route [{$route}] declares viewPath [{$viewPath}], but no such view exists. Requests matching it fall back to the package's own view. ".$hint);
            }
        }
    }

    /**
     * Ghost content lives in one store shared by every domain, and that store is
     * derived from `cache.default` unless the application names its own. A domain
     * that overrides `cache.default` therefore derives a *different* store, and
     * cross-domain invalidation silently stops working for it.
     *
     * @param  array<string, string>  $stores
     */
    private function warnAboutDivergingCacheStores(array $stores): void
    {
        if (filled(config('multidomain-ghost.cache.store'))) {
            return;
        }

        $default = (string) config('cache.default', '');

        foreach ($stores as $domain => $store) {
            if ($store === $default) {
                continue;
            }

            $this->warn("Domain [{$domain}] overrides cache.default to [{$store}]. Ghost content is cached in a store derived from cache.default, so this domain would use a different store and a webhook could not invalidate it. Set 'multidomain-ghost.cache.store' to a store declared in config/cache.php.");
        }
    }

    /**
     * Two domains under one prefix share a cache namespace - and with a cache
     * backed session driver, a session namespace too.
     */
    private function warnAboutSharedCachePrefixes(array $prefixes): void
    {
        $shared = array_keys(array_filter(
            array_count_values(array_filter($prefixes)),
            static fn (int $count): bool => $count > 1,
        ));

        foreach ($shared as $prefix) {
            $this->warn("Cache prefix [{$prefix}] is shared by more than one domain. Set 'cache.prefix' in each config/domains/{key}.php to keep them isolated.");
        }
    }
}
