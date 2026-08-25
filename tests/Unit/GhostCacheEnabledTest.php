<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use Illuminate\Support\Facades\Http;
use MrSonj\MultiDomainGhost\Client\GhostClient;
use MrSonj\MultiDomainGhost\Services\DomainResolver;
use MrSonj\MultiDomainGhost\Services\GhostContentService;
use MrSonj\MultiDomainGhost\Support\GhostCache;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class GhostCacheEnabledTest extends TestCase
{
    private function service(): GhostContentService
    {
        $this->app['config']->set('multidomain-ghost.url', 'https://ghost.example.com');
        $this->app['config']->set('multidomain-ghost.content_key', 'key');

        return new GhostContentService(
            new GhostClient('example.com', false),
            (new DomainResolver)->setDomain('example.com'),
        );
    }

    public function test_it_is_off_in_local_when_nothing_is_configured(): void
    {
        $this->app['env'] = 'local';

        $this->assertFalse(GhostCache::enabled());
    }

    public function test_it_is_on_outside_local_when_nothing_is_configured(): void
    {
        $this->app['env'] = 'production';

        $this->assertTrue(GhostCache::enabled());
    }

    public function test_local_can_opt_in_so_a_cache_bug_is_reproducible(): void
    {
        $this->app['env'] = 'local';
        $this->app['config']->set('multidomain-ghost.cache.enabled', true);

        Http::fake(['*' => Http::response(['posts' => [[
            'canonical_url' => 'https://example.com/a',
            'title' => 'Cached',
        ]]])]);

        $service = $this->service();
        $service->getPost('https://example.com/a');
        $service->getPost('https://example.com/a');

        Http::assertSentCount(1);
    }

    public function test_production_can_opt_out(): void
    {
        $this->app['env'] = 'production';
        $this->app['config']->set('multidomain-ghost.cache.enabled', false);

        Http::fake(['*' => Http::response(['posts' => [[
            'canonical_url' => 'https://example.com/a',
            'title' => 'Fresh',
        ]]])]);

        $service = $this->service();
        $service->getPost('https://example.com/a');
        $service->getPost('https://example.com/a');

        Http::assertSentCount(2);
    }
}
