<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use Illuminate\Http\Request;
use MrSonj\MultiDomainGhost\Services\DomainResolver;
use MrSonj\MultiDomainGhost\Support\DomainName;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class DomainHostResolutionTest extends TestCase
{
    public function test_normalize_keeps_ordinary_hosts_intact(): void
    {
        $this->assertSame('example.com', DomainName::normalize('EXAMPLE.com.'));
        $this->assertSame('example.com', DomainName::normalize('https://example.com/path'));
        $this->assertSame('example.com', DomainName::normalize('example.com:8080'));
    }

    public function test_normalize_rejects_a_host_containing_whitespace(): void
    {
        $this->assertSame('', DomainName::normalize('a b.com'));
    }

    public function test_normalize_rejects_a_host_carrying_header_injection(): void
    {
        $this->assertSame('', DomainName::normalize("evil.com\r\nX-Injected: 1"));
    }

    public function test_normalize_rejects_a_path_traversal_attempt(): void
    {
        $this->assertSame('', DomainName::normalize('../../etc'));
    }

    public function test_from_globals_ignores_an_unusable_host_header(): void
    {
        $this->assertNull(DomainName::fromGlobals(['HTTP_HOST' => 'a b.com']));
    }

    public function test_request_host_wins_over_the_raw_server_host(): void
    {
        $original = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'raw-header.example';

        try {
            $resolver = new DomainResolver;
            $request = Request::create('https://trusted.example/page');

            $this->assertSame(
                'trusted.example',
                $resolver->resolve($request),
                'the validated request host must win over the unvalidated HTTP_HOST global',
            );
        } finally {
            if ($original === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $original;
            }
        }
    }

    public function test_cli_domain_option_still_wins_over_the_request(): void
    {
        $this->assertSame(
            'cli.example',
            DomainName::fromGlobals([], ['artisan', 'queue:work', '--domain=cli.example']),
        );
    }

    public function test_resolved_domain_is_memoized(): void
    {
        $resolver = new DomainResolver;
        $request = Request::create('https://first.example/');

        $this->assertSame('first.example', $resolver->resolve($request));
        $this->assertSame('first.example', $resolver->resolve());

        $resolver->reset();
        $resolver->setDomain('second.example');

        $this->assertSame('second.example', $resolver->resolve());
    }
}
