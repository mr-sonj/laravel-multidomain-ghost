<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use MrSonj\MultiDomainGhost\Tests\TestCase;

class PublishedConfigTest extends TestCase
{
    private function publishedConfig(): array
    {
        return require __DIR__.'/../../config/multidomain-ghost.php';
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

    public function test_the_robots_and_seo_defaults_are_still_shipped(): void
    {
        $config = $this->publishedConfig();

        $this->assertSame(['/cdn-cgi/'], $config['robots']['disallow']);
        $this->assertSame('https://{domain}/sitemap.xml', $config['robots']['sitemap']);
        $this->assertSame(
            'https://{domain}/img/{domain_key}/apple-touch-icon.png',
            $config['seo']['default_image'],
        );
    }
}
