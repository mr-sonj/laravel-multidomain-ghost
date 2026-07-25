<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use MrSonj\MultiDomainGhost\Client\GhostClient;
use MrSonj\MultiDomainGhost\Services\DomainResolver;
use MrSonj\MultiDomainGhost\Services\GhostContentService;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class GhostContentServiceTest extends TestCase
{
    public function test_cache_ttl_uses_config(): void
    {
        $this->app['config']->set('multidomain-ghost.cache_ttl', 3600);
        $this->get('http://example.com');

        $clientMock = $this->createMock(GhostClient::class);
        $clientMock->method('list')->willReturn(['posts' => []]);

        $service = new GhostContentService($clientMock, new DomainResolver);

        $this->assertEquals('example.com', $service->domain());

        Cache::shouldReceive('remember')
            ->once()
            ->with('ghost:example.com:dataBlog:1:1:15', 3600, \Closure::class)
            ->andReturn(['posts' => []]);
        Cache::shouldReceive('get')
            ->once()
            ->with('ghost:example.com:dataBlog:generation', '1')
            ->andReturn('1');

        $service->dataBlog(1, 15);
    }
}
