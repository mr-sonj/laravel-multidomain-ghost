<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Console\Commands;

use Illuminate\Console\Command;
use MrSonj\MultiDomainGhost\Services\DomainResolver;
use MrSonj\MultiDomainGhost\Support\DomainEnricherLocator;
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
            $this->warn('No domains registered in config/domain.php or GHOST_REGISTERED_DOMAINS.');

            return self::SUCCESS;
        }

        $rows = [];
        $prefixes = [];
        $stores = [];

        foreach ($domains as $domain) {
            $sanitized = DomainResolver::dirKeyFor($domain);
            $cachePrefix = $this->cachePrefixFor($sanitized);
            $prefixes[] = $cachePrefix;
            $stores[$domain] = $this->cacheStoreFor($sanitized);

            $rows[] = [
                $domain,
                $sanitized,
                DomainResolver::domainTagSlug($domain),
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

        return self::SUCCESS;
    }

    /**
     * The cache prefix a request for this domain actually runs under: its own
     * config/domains override when present, otherwise the application default.
     */
    private function cachePrefixFor(string $sanitized): string
    {
        $overrides = (array) config("domains.{$sanitized}", []);

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
    private function cacheStoreFor(string $sanitized): string
    {
        $overrides = (array) config("domains.{$sanitized}", []);

        return filled($overrides['cache.default'] ?? null)
            ? (string) $overrides['cache.default']
            : (string) config('cache.default', '');
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
