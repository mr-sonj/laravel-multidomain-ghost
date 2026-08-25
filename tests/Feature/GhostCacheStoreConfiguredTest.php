<?php

namespace MrSonj\MultiDomainGhost\Tests\Feature;

use MrSonj\MultiDomainGhost\Support\GhostCache;
use MrSonj\MultiDomainGhost\Tests\TestCase;

/**
 * An application that declares its own store in config/cache.php owns that
 * decision: the package must read it and never write a store of its own.
 */
class GhostCacheStoreConfiguredTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.stores.ghost_owned', [
            'driver' => 'array',
            'serialize' => false,
        ]);
        $app['config']->set('multidomain-ghost.cache.store', 'ghost_owned');
    }

    public function test_a_store_declared_by_the_application_is_used_as_is(): void
    {
        $this->assertSame('ghost_owned', GhostCache::storeName());
        $this->assertNull($this->app['config']->get('cache.stores.'.GhostCache::STORE));
        $this->assertNotNull(GhostCache::repository());
    }
}
