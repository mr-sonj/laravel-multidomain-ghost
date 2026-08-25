<?php

namespace MrSonj\MultiDomainGhost\Tests\Feature;

use Illuminate\Http\Request;
use MrSonj\MultiDomainGhost\Http\Middleware\EnsureRegisteredDomain;
use MrSonj\MultiDomainGhost\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EnsureRegisteredDomainTest extends TestCase
{
    public function test_it_allows_registered_domains_and_their_www_redirect_alias(): void
    {
        $this->setRegisteredDomains(['example_com' => []]);
        $middleware = new EnsureRegisteredDomain;

        foreach (['example.com', 'www.example.com'] as $host) {
            $response = $middleware->handle(
                Request::create("https://{$host}/"),
                static fn () => response('ok'),
            );

            $this->assertSame(200, $response->getStatusCode());
        }
    }

    public function test_it_rejects_a_route_whose_domain_config_was_removed(): void
    {
        $this->setRegisteredDomains([]);

        $this->expectException(NotFoundHttpException::class);

        (new EnsureRegisteredDomain)->handle(
            Request::create('https://removed.example.com/'),
            static fn () => response('should not run'),
        );
    }
}
