<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves the cache repository Ghost content is stored in.
 *
 * Ghost cache keys already carry their own domain (`ghost:{domain}:...`), so they
 * must not additionally depend on the per-domain `cache.prefix`. Sharing one store
 * across every domain is what makes webhook invalidation deterministic: the domain
 * a webhook arrives on no longer decides which domains can be purged.
 */
final class GhostCache
{
    public const STORE = 'multidomain-ghost';

    public static function repository(): Repository
    {
        self::ensureStoreRegistered();

        return Cache::store(self::storeName());
    }

    /**
     * Declare the fallback store in the cache configuration.
     *
     * Called from the service provider so the store exists in configuration
     * rather than only in memory once something happens to touch this class:
     * `cache:clear multidomain-ghost` resolves the store straight from
     * config/cache.php and never goes through this package. Idempotent, and a
     * no-op when the application named a store of its own.
     */
    public static function ensureStoreRegistered(): void
    {
        if (self::storeName() !== self::STORE) {
            return;
        }

        if (is_array(config('cache.stores.'.self::STORE))) {
            return;
        }

        self::provisionStore();
    }

    /**
     * The store Ghost content lives in. Consumers may point this at a store they
     * declared themselves through `multidomain-ghost.cache.store`.
     */
    public static function storeName(): string
    {
        $configured = config('multidomain-ghost.cache.store');

        return filled($configured) ? (string) $configured : self::STORE;
    }

    public static function ttl(): int
    {
        return (int) config(
            'multidomain-ghost.cache.ttl',
            config('multidomain-ghost.cache_ttl', 60 * 60 * 24 * 30),
        );
    }

    /**
     * Lifetime for "this URL does not exist" answers. Short by design: without it
     * every request for a missing URL reaches Ghost again.
     */
    public static function missTtl(): int
    {
        return max(0, (int) config('multidomain-ghost.cache.miss_ttl', 300));
    }

    /**
     * Lifetime for successful responses that came back empty. A misconfigured tag
     * should not freeze an empty sitemap in place for the full TTL.
     */
    public static function emptyTtl(): int
    {
        return max(0, (int) config('multidomain-ghost.cache.empty_ttl', 300));
    }

    /**
     * Derive a dedicated store from the application's default one, keeping its
     * driver and connection but pinning a stable, domain-independent prefix.
     */
    private static function provisionStore(): void
    {
        $default = (string) config('cache.default');
        $config = (array) config("cache.stores.{$default}", ['driver' => 'array']);

        $config['prefix'] = (string) config('multidomain-ghost.cache.prefix', 'multidomain_ghost');

        if (($config['driver'] ?? null) === 'file') {
            $config['path'] = app()->basePath('storage/framework/cache/multidomain-ghost');
            unset($config['lock_path']);
        }

        config(['cache.stores.'.self::STORE => $config]);
    }
}
