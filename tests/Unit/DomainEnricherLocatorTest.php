<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use MrSonj\MultiDomainGhost\Support\DomainEnricherLocator;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class DomainEnricherLocatorTest extends TestCase
{
    public function test_builds_the_convention_class_for_an_ordinary_domain(): void
    {
        $this->assertSame(
            'App\Services\example_com\ExampleComEnricher',
            DomainEnricherLocator::conventionClassFor('example.com'),
        );
    }

    public function test_rejects_a_domain_starting_with_a_digit(): void
    {
        $this->assertNull(
            DomainEnricherLocator::conventionClassFor('10mailbox.com'),
            'a namespace segment cannot start with a digit, so the convention cannot apply',
        );
    }

    public function test_rejects_a_domain_containing_a_hyphen(): void
    {
        $this->assertNull(
            DomainEnricherLocator::conventionClassFor('my-site.com'),
            'a hyphen is not legal in a PHP namespace segment',
        );
    }

    public function test_resolve_prefers_an_explicitly_configured_enricher(): void
    {
        $this->app['config']->set('multidomain-ghost.enrichers', [
            '10mailbox.com' => TestCustomEnricher::class,
        ]);

        $this->assertSame(
            TestCustomEnricher::class,
            DomainEnricherLocator::resolveClass('10mailbox.com'),
        );
    }

    public function test_resolve_returns_null_when_nothing_is_wired_up(): void
    {
        $this->assertNull(DomainEnricherLocator::resolveClass('unmapped.com'));
    }

    public function test_resolve_never_probes_an_illegal_class_name(): void
    {
        $this->assertNull(
            DomainEnricherLocator::resolveClass('10mailbox.com'),
            'domains the convention cannot express must fall through to the config map',
        );
    }
}
