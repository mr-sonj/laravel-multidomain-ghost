<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use MrSonj\MultiDomainGhost\Client\GhostClient;
use MrSonj\MultiDomainGhost\Services\DomainResolver;
use MrSonj\MultiDomainGhost\Services\GhostContentService;
use MrSonj\MultiDomainGhost\Support\GhostCache;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class GhostContentServiceTest extends TestCase
{
    public function test_cache_ttl_falls_back_to_the_legacy_config_key(): void
    {
        $this->app['config']->set('multidomain-ghost.cache', []);
        $this->app['config']->set('multidomain-ghost.cache_ttl', 3600);

        $this->assertSame(3600, GhostCache::ttl());
    }

    public function test_cache_ttl_prefers_the_namespaced_config_key(): void
    {
        $this->app['config']->set('multidomain-ghost.cache_ttl', 3600);
        $this->app['config']->set('multidomain-ghost.cache.ttl', 60);

        $this->assertSame(60, GhostCache::ttl());
    }

    public function test_blog_listing_is_served_from_cache_on_the_second_call(): void
    {
        $this->get('http://example.com');

        $calls = 0;
        $client = $this->createMock(GhostClient::class);
        $client->method('list')->willReturnCallback(function () use (&$calls) {
            $calls++;

            return ['posts' => [['title' => 'One']]];
        });

        $service = new GhostContentService($client, new DomainResolver);
        $this->assertSame('example.com', $service->domain());

        $service->dataBlog(1, 15);
        $service->dataBlog(1, 15);

        $this->assertSame(1, $calls, 'dataBlog() should only reach Ghost once for the same page');
    }

    public function test_blog_cache_key_is_scoped_by_domain_page_and_generation(): void
    {
        $this->get('http://example.com');

        $service = new GhostContentService($this->createMock(GhostClient::class), new DomainResolver);

        $this->assertSame('ghost:example.com:dataBlog:1:2:15', $service->blogCacheKey(2, 15));
    }
}
