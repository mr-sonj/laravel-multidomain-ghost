<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use MrSonj\MultiDomainGhost\Client\GhostClient;
use MrSonj\MultiDomainGhost\Services\DomainResolver;
use MrSonj\MultiDomainGhost\Support\Domain;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class DomainTest extends TestCase
{
    public function test_it_derives_every_form_from_one_hostname(): void
    {
        $domain = Domain::make('10mailbox.com');

        $this->assertSame('10mailbox.com', $domain->host());
        $this->assertSame('10mailbox_com', $domain->key());
        $this->assertSame('hash-10mailbox-com', $domain->tag());
        $this->assertSame('tag:hash-10mailbox-com', $domain->filter());
        $this->assertSame('10mailbox.com', (string) $domain);
    }

    public function test_it_normalizes_its_input(): void
    {
        $this->assertSame('example.com', Domain::make('HTTPS://Example.COM./path')->host());
        $this->assertSame('example_com', Domain::make('  Example.com  ')->key());
    }

    public function test_the_tag_prefix_is_read_at_call_time(): void
    {
        $domain = Domain::make('example.com');
        $this->app['config']->set('multidomain-ghost.domain_tag_prefix', 'site-');

        $this->assertSame('site-example-com', $domain->tag());
        $this->assertSame('tag:site-example-com', $domain->filter());
    }

    public function test_an_unusable_host_is_not_valid(): void
    {
        $this->assertFalse(Domain::make('not a host')->isValid());
        $this->assertSame('', Domain::make('not a host')->host());
        $this->assertTrue(Domain::make('example.com')->isValid());
    }

    public function test_the_deprecated_statics_still_answer_identically(): void
    {
        $domain = Domain::make('example.com');

        $this->assertSame($domain->tag(), DomainResolver::domainTagSlug('example.com'));
        $this->assertSame($domain->tag(), GhostClient::domainTagSlug('example.com'));
        $this->assertSame($domain->key(), DomainResolver::dirKeyFor('example.com'));
        $this->assertSame($domain->host(), DomainResolver::normalizeDomain('example.com'));
    }

    public function test_the_resolver_instance_methods_agree_with_the_value_object(): void
    {
        $resolver = (new DomainResolver)->setDomain('example.com');

        $this->assertSame('example_com', $resolver->dirKey());
        $this->assertSame('tag:hash-example-com', $resolver->domainFilter());
    }
}
