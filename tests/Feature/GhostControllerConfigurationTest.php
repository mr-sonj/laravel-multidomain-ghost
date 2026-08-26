<?php

namespace MrSonj\MultiDomainGhost\Tests\Feature;

use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;
use MrSonj\MultiDomainGhost\Services\GhostContentService;
use MrSonj\MultiDomainGhost\Support\NullEnricher;
use MrSonj\MultiDomainGhost\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GhostControllerConfigurationTest extends TestCase
{
    private function controller(string $domain = 'example.com'): GhostController
    {
        $content = $this->createMock(GhostContentService::class);
        $content->method('domain')->willReturn($domain);

        return new GhostController(new NullEnricher, $content);
    }

    public function test_default_seo_image_follows_the_documented_convention(): void
    {
        $seo = $this->controller()->seoData(['domain' => 'example.com']);

        $this->assertSame(
            'https://example.com/img/example_com/apple-touch-icon.png',
            $seo['image'],
        );
    }

    public function test_default_seo_image_is_configurable(): void
    {
        $this->app['config']->set(
            'multidomain-ghost.seo.default_image',
            'https://cdn.example.net/{domain_key}/social.png',
        );

        $seo = $this->controller()->seoData(['domain' => 'example.com']);

        $this->assertSame('https://cdn.example.net/example_com/social.png', $seo['image']);
    }

    public function test_content_feature_image_still_wins_over_the_default(): void
    {
        $seo = $this->controller()->seoData([
            'domain' => 'example.com',
            'feature_image' => 'https://example.com/hero.jpg',
        ]);

        $this->assertSame('https://example.com/hero.jpg', $seo['image']);
    }

    public function test_robots_sitemap_line_is_configurable(): void
    {
        $this->app['config']->set('multidomain-ghost.robots.sitemap', 'https://cdn.example.net/sitemap-index.xml');

        $robots = $this->controller()->robots()->getContent();

        $this->assertStringContainsString('Sitemap: https://cdn.example.net/sitemap-index.xml', $robots);
    }

    public function test_robots_defaults_to_the_domains_own_sitemap(): void
    {
        $robots = $this->controller()->robots()->getContent();

        $this->assertStringContainsString('Sitemap: https://example.com/sitemap.xml', $robots);
        $this->assertStringContainsString('Disallow: /cdn-cgi/', $robots);
    }

    public function test_ads_txt_reads_the_domains_own_file(): void
    {
        $this->setDomainAssets(['example_com/ads.txt' => "google.com, pub-1, DIRECT, f08c\n"]);

        $this->assertSame('google.com, pub-1, DIRECT, f08c', $this->controller()->ads()->getContent());
    }

    public function test_ads_txt_is_not_found_when_the_domain_has_no_file(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller()->ads();
    }

    public function test_ads_txt_files_are_isolated_per_domain(): void
    {
        $this->setDomainAssets(['example_com/ads.txt' => 'google.com, pub-1, DIRECT']);

        $this->assertSame('google.com, pub-1, DIRECT', $this->controller('example.com')->ads()->getContent());

        $this->expectException(NotFoundHttpException::class);
        $this->controller('other.com')->ads();
    }

    public function test_seo_data_falls_back_to_the_active_domain_when_content_has_none(): void
    {
        $seo = $this->controller()->seoData(['title' => 'Hand built']);

        $this->assertSame('https://example.com/#website', $seo['json_ld']['is_part_of']);
    }
}
