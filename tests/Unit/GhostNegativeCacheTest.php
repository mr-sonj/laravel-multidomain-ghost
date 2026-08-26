<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use Illuminate\Support\Facades\Http;
use MrSonj\MultiDomainGhost\Client\GhostClient;
use MrSonj\MultiDomainGhost\Services\DomainResolver;
use MrSonj\MultiDomainGhost\Services\GhostContentService;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class GhostNegativeCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('multidomain-ghost.url', 'https://cms.example.com');
        $this->app['config']->set('multidomain-ghost.content_key', 'content-key');
    }

    private function service(string $domain = 'example.com'): GhostContentService
    {
        return new GhostContentService(
            new GhostClient($domain, false),
            (new DomainResolver)->setDomain($domain),
        );
    }

    private function upstreamCallCount(): int
    {
        $count = 0;
        Http::recorded(function () use (&$count) {
            $count++;

            return false;
        });

        return $count;
    }

    public function test_a_missing_url_only_reaches_ghost_once(): void
    {
        Http::fake(['*' => Http::response(['posts' => []])]);

        $service = $this->service();
        $service->getPost('https://example.com/does-not-exist');
        $afterFirst = $this->upstreamCallCount();

        $service->getPost('https://example.com/does-not-exist');

        $this->assertSame(
            $afterFirst,
            $this->upstreamCallCount(),
            'a repeated request for a missing URL must be answered from cache',
        );
    }

    public function test_a_cached_miss_is_still_reported_as_missing(): void
    {
        Http::fake(['*' => Http::response(['posts' => []])]);

        $service = $this->service();
        $service->getPost('https://example.com/does-not-exist');

        $this->assertNull($service->getPost('https://example.com/does-not-exist'));
    }

    public function test_purging_a_url_lets_newly_published_content_appear_immediately(): void
    {
        $published = false;
        Http::fake(function () use (&$published) {
            return $published
                ? Http::response(['posts' => [[
                    'canonical_url' => 'https://example.com/new-post',
                    'title' => 'Just published',
                ]]])
                : Http::response(['posts' => []]);
        });

        $service = $this->service();
        $service->getPost('https://example.com/new-post');

        $published = true;
        $service->forgetPostCache('https://example.com/new-post');

        $content = $service->getPost('https://example.com/new-post');

        $this->assertIsArray($content);
        $this->assertSame('Just published', $content['title']);
    }

    public function test_local_environment_never_serves_a_cached_miss(): void
    {
        $this->app['env'] = 'local';
        Http::fake(['*' => Http::response(['posts' => []])]);

        $service = $this->service();
        $service->getPost('https://example.com/draft');
        $afterFirst = $this->upstreamCallCount();

        $service->getPost('https://example.com/draft');

        $this->assertGreaterThan(
            $afterFirst,
            $this->upstreamCallCount(),
            'local development must always ask Ghost again',
        );
    }
}
