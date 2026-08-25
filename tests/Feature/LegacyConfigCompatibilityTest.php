<?php

namespace MrSonj\MultiDomainGhost\Tests\Feature;

use Illuminate\Support\Facades\Http;
use MrSonj\MultiDomainGhost\Client\GhostClient;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;
use MrSonj\MultiDomainGhost\Services\DomainResolver;
use MrSonj\MultiDomainGhost\Services\GhostContentService;
use MrSonj\MultiDomainGhost\Support\GhostCache;
use MrSonj\MultiDomainGhost\Support\NullEnricher;
use MrSonj\MultiDomainGhost\Tests\TestCase;

/**
 * An application that published config/multidomain-ghost.php before this version
 * has none of the new keys, and mergeConfigFrom() is shallow - a published
 * `robots` block replaces the package one wholesale. Nothing may break.
 */
class LegacyConfigCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Exactly the shape an older published config file has.
        $this->app['config']->set('multidomain-ghost', [
            'url' => 'https://cms.example.com',
            'content_key' => 'content-key',
            'api_mode' => 'content',
            'api_version' => 'v6.0',
            'timeout' => 10,
            'retry_times' => 2,
            'retry_sleep' => 0,
            'cache_ttl' => 1234,
            'domain_tag_prefix' => 'hash-',
            'webhook_secret' => 'secret123',
            'domains' => ['example.com'],
            'views' => ['page' => 'multidomain-ghost::page'],
            'robots' => ['content_signal' => 'search=yes'],
            'enrichers' => [],
            'transformer' => null,
        ]);
    }

    private function controller(): GhostController
    {
        $content = $this->createMock(GhostContentService::class);
        $content->method('domain')->willReturn('example.com');

        return new GhostController(new NullEnricher, $content);
    }

    public function test_cache_ttl_from_the_legacy_key_is_honoured(): void
    {
        $this->assertSame(1234, GhostCache::ttl());
    }

    public function test_the_ghost_cache_store_is_still_provisioned(): void
    {
        $this->assertNotNull(GhostCache::repository());
    }

    public function test_robots_still_emits_a_sitemap_line_without_the_new_key(): void
    {
        $robots = $this->controller()->robots()->getContent();

        $this->assertStringContainsString('Sitemap: https://example.com/sitemap.xml', $robots);
        $this->assertStringContainsString('Content-Signal: search=yes', $robots);
    }

    public function test_seo_falls_back_to_the_documented_default_image(): void
    {
        $seo = $this->controller()->seoData(['domain' => 'example.com']);

        $this->assertSame('https://example.com/img/example_com/apple-touch-icon.png', $seo['image']);
    }

    public function test_content_lookup_works_without_the_new_cache_block(): void
    {
        Http::fake(['*' => Http::response(['posts' => [[
            'canonical_url' => 'https://example.com/a',
            'title' => 'Legacy',
        ]]])]);

        $service = new GhostContentService(
            new GhostClient('example.com', false),
            (new DomainResolver)->setDomain('example.com'),
        );

        $this->assertSame('Legacy', $service->getPost('https://example.com/a')['title']);
        $this->assertSame('Legacy', $service->getPost('https://example.com/a')['title']);
    }
}
