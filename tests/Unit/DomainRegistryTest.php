<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use MrSonj\MultiDomainGhost\Support\DomainRegistry;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class DomainRegistryTest extends TestCase
{
    public function test_the_package_allowlist_wins_when_it_is_populated(): void
    {
        $this->app['config']->set('multidomain-ghost.domains', ['Env-Only.COM']);
        $this->app['config']->set('domain.domains', ['legacy.com' => 'legacy.com']);

        $this->assertSame(['env-only.com'], DomainRegistry::all());
    }

    public function test_it_falls_back_to_the_legacy_registry_keys(): void
    {
        $this->app['config']->set('multidomain-ghost.domains', []);
        $this->app['config']->set('domain.domains', [
            'legacy.com' => 'legacy.com',
            'other.com' => 'other.com',
        ]);

        $this->assertSame(['legacy.com', 'other.com'], DomainRegistry::all());
    }

    public function test_it_drops_duplicates_and_unusable_hostnames(): void
    {
        $this->app['config']->set('multidomain-ghost.domains', [
            'example.com',
            'EXAMPLE.com',
            'not a host',
        ]);

        $this->assertSame(['example.com'], DomainRegistry::all());
    }
}
