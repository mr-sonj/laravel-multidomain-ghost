<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use Illuminate\Support\Facades\Http;
use MrSonj\MultiDomainGhost\Client\GhostClient;
use MrSonj\MultiDomainGhost\Services\DomainResolver;
use MrSonj\MultiDomainGhost\Services\GhostContentService;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class GhostEmptyResultCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('multidomain-ghost.url', 'https://cms.example.com');
        $this->app['config']->set('multidomain-ghost.content_key', 'content-key');
        $this->app['config']->set('multidomain-ghost.cache.ttl', 60 * 60 * 24 * 30);
        $this->app['config']->set('multidomain-ghost.cache.empty_ttl', 300);
    }

    private function service(): GhostContentService
    {
        return new GhostContentService(
            new GhostClient('example.com', false),
            (new DomainResolver)->setDomain('example.com'),
        );
    }

    private function upstreamCallCount(): int
    {
        $count = 0;
        Http::recorded(function () use (&$count) {
            $count++;

            return false;
        });

        return $count;
    }

    public function test_an_empty_slug_list_is_cached_only_briefly(): void
    {
        Http::fake(['*' => Http::response(['posts' => [], 'pages' => []])]);

        $service = $this->service();
        $service->slugs();
        $afterFirst = $this->upstreamCallCount();

        $service->slugs();
        $this->assertSame($afterFirst, $this->upstreamCallCount(), 'an empty list should still be cached briefly');

        $this->travel(301)->seconds();
        $service->slugs();

        $this->assertGreaterThan(
            $afterFirst,
            $this->upstreamCallCount(),
            'an empty slug list must expire quickly so a mistyped tag cannot freeze an empty sitemap',
        );
    }

    public function test_a_populated_slug_list_keeps_the_full_cache_lifetime(): void
    {
        Http::fake(['*' => Http::response(['posts' => [[
            'canonical_url' => 'https://example.com/a',
            'slug' => 'a',
        ]], 'pages' => []])]);

        $service = $this->service();
        $service->slugs();
        $afterFirst = $this->upstreamCallCount();

        $this->travel(301)->seconds();
        $service->slugs();

        $this->assertSame(
            $afterFirst,
            $this->upstreamCallCount(),
            'a populated slug list must keep the full TTL',
        );
    }
}
