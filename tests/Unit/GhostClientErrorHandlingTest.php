<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use MrSonj\MultiDomainGhost\Client\GhostClient;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class GhostClientErrorHandlingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('multidomain-ghost.url', 'https://cms.example.com');
        $this->app['config']->set('multidomain-ghost.content_key', 'content-key');
        $this->app['config']->set('multidomain-ghost.retry_sleep', 0);
    }

    public function test_content_returns_null_when_ghost_is_unavailable(): void
    {
        Http::fake(['*' => Http::response('upstream down', 503)]);

        $this->assertNull((new GhostClient('example.com', false))->content('https://example.com/a'));
    }

    public function test_list_returns_null_when_ghost_rejects_the_credentials(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['message' => 'Unauthorized']]], 401)]);

        $this->assertNull((new GhostClient('example.com', false))->list());
    }

    public function test_content_returns_null_when_ghost_cannot_be_reached(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        $this->assertNull((new GhostClient('example.com', false))->content('https://example.com/a'));
    }

    public function test_content_still_falls_back_to_pages_when_the_posts_endpoint_errors(): void
    {
        Http::fake([
            '*/content/posts*' => Http::response('boom', 500),
            '*/content/pages*' => Http::response(['pages' => [[
                'canonical_url' => 'https://example.com/a',
                'title' => 'A page',
            ]]]),
        ]);

        $content = (new GhostClient('example.com', false))->content('https://example.com/a');

        $this->assertIsArray($content);
        $this->assertSame('A page', $content['title']);
    }

    public function test_slugs_returns_an_empty_list_when_ghost_is_unavailable(): void
    {
        Http::fake(['*' => Http::response('upstream down', 502)]);

        $this->assertSame([], (new GhostClient('example.com', false))->slugs());
    }
}
