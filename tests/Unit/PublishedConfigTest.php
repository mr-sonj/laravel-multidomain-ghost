<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;
use MrSonj\MultiDomainGhost\Services\GhostContentService;
use MrSonj\MultiDomainGhost\Support\Domain;
use MrSonj\MultiDomainGhost\Support\GhostCache;
use MrSonj\MultiDomainGhost\Support\NullEnricher;
use MrSonj\MultiDomainGhost\Tests\TestCase;

/**
 * The shipped file carries only the keys worth deciding per deployment. Every key
 * it drops must still have a default in the code, so these tests assert the
 * resulting behaviour rather than the file's contents - the file is the thing
 * being trimmed, so asserting against it would only ever restate the trim.
 */
class PublishedConfigTest extends TestCase
{
    private function publishedConfig(): array
    {
        return require __DIR__.'/../../config/multidomain-ghost.php';
    }

    private function controller(): GhostController
    {
        $content = $this->createMock(GhostContentService::class);
        $content->method('domain')->willReturn('example.com');

        return new GhostController(new NullEnricher, $content);
    }

    public function test_it_no_longer_ships_a_shared_ads_block(): void
    {
        $this->assertArrayNotHasKey('ads', $this->publishedConfig());
    }

    public function test_the_content_signal_env_key_carries_the_package_prefix(): void
    {
        putenv('GHOST_ROBOTS_CONTENT_SIGNAL=search=yes,ai-train=no');

        try {
            $this->assertSame(
                'search=yes,ai-train=no',
                $this->publishedConfig()['robots']['content_signal'],
            );
        } finally {
            putenv('GHOST_ROBOTS_CONTENT_SIGNAL');
        }
    }

    public function test_the_unprefixed_content_signal_env_key_is_no_longer_read(): void
    {
        putenv('ROBOTS_CONTENT_SIGNAL=search=yes');

        try {
            $this->assertSame('', $this->publishedConfig()['robots']['content_signal']);
        } finally {
            putenv('ROBOTS_CONTENT_SIGNAL');
        }
    }

    public function test_the_redundant_keys_are_gone(): void
    {
        $config = $this->publishedConfig();

        foreach ([
            'jwt_audience',
            'verify_ssl',
            'retry_times',
            'retry_sleep',
            'domain_tag_prefix',
            'allow_unsigned_webhooks',
            'webhook_tolerance',
            'views',
            'seo',
        ] as $key) {
            $this->assertArrayNotHasKey($key, $config);
        }
    }

    public function test_the_trimmed_cache_and_route_keys_are_gone(): void
    {
        $config = $this->publishedConfig();

        foreach (['prefix', 'miss_ttl', 'empty_ttl'] as $key) {
            $this->assertArrayNotHasKey($key, $config['cache']);
        }

        foreach (['paths', 'middleware', 'redirect_www', 'webhook'] as $key) {
            $this->assertArrayNotHasKey($key, $config['routes']);
        }

        $this->assertArrayNotHasKey('sitemap', $config['robots']);
        $this->assertArrayNotHasKey('disallow', $config['robots']);
    }

    public function test_robots_still_carries_the_generated_policy_without_its_keys(): void
    {
        $robots = $this->controller()->robots()->getContent();

        $this->assertStringContainsString('Disallow: /cdn-cgi/', $robots);
        $this->assertStringContainsString('Sitemap: https://example.com/sitemap.xml', $robots);
    }

    public function test_the_seo_image_falls_back_to_the_documented_template(): void
    {
        $seo = $this->controller()->seoData(['domain' => 'example.com']);

        $this->assertSame('https://example.com/img/example_com/apple-touch-icon.png', $seo['image']);
    }

    public function test_the_trimmed_cache_lifetimes_keep_their_defaults(): void
    {
        $this->assertSame(300, GhostCache::missTtl());
        $this->assertSame(300, GhostCache::emptyTtl());
        $this->assertSame(60 * 60 * 24 * 30, GhostCache::ttl());
    }

    public function test_the_domain_tag_prefix_keeps_its_default(): void
    {
        $this->assertSame('hash-example-com', Domain::make('example.com')->tag());
    }
}
