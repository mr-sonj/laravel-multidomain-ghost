<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use MrSonj\MultiDomainGhost\Support\DomainAssets;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class DomainAssetsTest extends TestCase
{
    public function test_it_reads_a_file_the_domain_owns(): void
    {
        $this->setDomainAssets(['example_com/ads.txt' => "google.com, pub-1, DIRECT\n"]);

        $this->assertSame(
            'google.com, pub-1, DIRECT',
            DomainAssets::contents('example.com', 'ads.txt'),
        );
    }

    public function test_it_returns_null_when_the_domain_has_no_such_file(): void
    {
        $this->assertNull(DomainAssets::contents('example.com', 'ads.txt'));
    }

    public function test_an_empty_file_is_indistinguishable_from_a_missing_one(): void
    {
        $this->setDomainAssets(['example_com/ads.txt' => "   \n\n"]);

        $this->assertNull(DomainAssets::contents('example.com', 'ads.txt'));
    }

    public function test_each_domain_reads_its_own_file(): void
    {
        $this->setDomainAssets([
            'example_com/ads.txt' => 'google.com, pub-1, DIRECT',
            'other_com/ads.txt' => 'google.com, pub-2, DIRECT',
        ]);

        $this->assertSame('google.com, pub-1, DIRECT', DomainAssets::contents('example.com', 'ads.txt'));
        $this->assertSame('google.com, pub-2, DIRECT', DomainAssets::contents('other.com', 'ads.txt'));
    }

    public function test_the_path_uses_the_directory_safe_domain_key(): void
    {
        $this->assertSame(
            resource_path('domains/my-sample-blog_co_uk/robots.txt'),
            DomainAssets::path('my-sample-blog.co.uk', 'robots.txt'),
        );
    }

    public function test_a_host_that_is_not_a_hostname_cannot_escape_the_assets_directory(): void
    {
        $this->assertStringNotContainsString('..', DomainAssets::path('../../etc', 'passwd'));
        $this->assertNull(DomainAssets::contents('../../etc', 'passwd'));
    }
}
