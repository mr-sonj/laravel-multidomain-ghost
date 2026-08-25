<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use MrSonj\MultiDomainGhost\Support\DomainRegistry;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class DomainRegistryTest extends TestCase
{
    public function test_it_discovers_registered_domains_from_domains_config_keys(): void
    {
        $this->app['config']->set('domains', [
            'example_com' => ['app.name' => 'Example'],
            'other-site_com' => ['app.name' => 'Other'],
        ]);

        $this->assertSame(['example.com', 'other-site.com'], DomainRegistry::all());
    }

    public function test_it_handles_multi_level_subdomains_and_hyphens(): void
    {
        $this->app['config']->set('domains', [
            'sub_domain_example_com' => [],
            'my_blog_net' => [],
        ]);

        $this->assertSame(['sub.domain.example.com', 'my.blog.net'], DomainRegistry::all());
    }

    public function test_it_drops_duplicates_and_unusable_hostnames(): void
    {
        $this->app['config']->set('domains', [
            'example_com' => [],
            'not a host' => [],
            'EXAMPLE_COM' => [],
        ]);

        $this->assertSame(['example.com'], DomainRegistry::all());
    }

    public function test_it_returns_empty_when_no_domains_are_configured(): void
    {
        $this->app['config']->set('domains', []);

        $this->assertSame([], DomainRegistry::all());
    }
}
