<?php

namespace MrSonj\MultiDomainGhost\Tests\Feature;

use MrSonj\MultiDomainGhost\Support\GhostCache;
use MrSonj\MultiDomainGhost\Tests\TestCase;

/**
 * The Ghost store has to exist in config/cache.php terms, not only in memory the
 * first time GhostCache is touched: artisan commands such as `cache:clear` look
 * the store up straight from configuration and never go through this package.
 */
class GhostCacheStoreRegistrationTest extends TestCase
{
    public function test_the_store_is_defined_without_anything_touching_the_package_first(): void
    {
        $this->assertIsArray($this->app['config']->get('cache.stores.'.GhostCache::STORE));
    }

    public function test_cache_clear_can_target_the_ghost_store_by_name(): void
    {
        $this->artisan('cache:clear', ['store' => GhostCache::STORE])
            ->assertExitCode(0);
    }

    public function test_the_store_keeps_the_default_driver_and_a_fixed_prefix(): void
    {
        $store = (array) $this->app['config']->get('cache.stores.'.GhostCache::STORE);

        $this->assertSame(
            $this->app['config']->get('cache.stores.'.$this->app['config']->get('cache.default').'.driver'),
            $store['driver'] ?? null,
        );
        $this->assertSame('multidomain_ghost', $store['prefix'] ?? null);
    }
}
