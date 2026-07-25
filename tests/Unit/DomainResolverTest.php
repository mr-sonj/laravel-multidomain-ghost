<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use MrSonj\MultiDomainGhost\Services\DomainResolver;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class DomainResolverTest extends TestCase
{
    public function test_resolves_domain_tag_slug(): void
    {
        $this->assertEquals('hash-example-com', DomainResolver::domainTagSlug('example.com'));
        $this->assertEquals('hash-test-org', DomainResolver::domainTagSlug('test.org'));
    }

    public function test_resolves_domain_from_request(): void
    {
        $resolver = new DomainResolver;
        $this->get('http://example.com');
        $this->assertEquals('example.com', $resolver->resolve());
    }
}
