<?php

namespace MrSonj\MultiDomainGhost\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use MrSonj\MultiDomainGhost\Contracts\DomainEnricherInterface;
use MrSonj\MultiDomainGhost\Events\GhostPostUpdated;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;
use MrSonj\MultiDomainGhost\Services\GhostContentService;
use MrSonj\MultiDomainGhost\Support\NullEnricher;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class GhostControllerTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app->bind(DomainEnricherInterface::class, NullEnricher::class);
        $app['config']->set('multidomain-ghost.domains', ['example.com']);
        $app['config']->set('multidomain-ghost.webhook_secret', 'secret123');
    }

    public function test_seo_data_fallback_title(): void
    {
        $this->app['config']->set('app.name', 'My Application');
        /** @var GhostController $controller */
        $controller = $this->app->make(GhostController::class);

        $seo = $controller->seoData(['domain' => 'example.com']);
        $this->assertEquals('My Application', $seo['title']);
    }

    public function test_page_uses_the_package_fallback_without_published_config_or_views(): void
    {
        $content = $this->createMock(GhostContentService::class);
        $content->method('domain')->willReturn('example.com');
        $content->method('getPost')->willReturn([
            'domain' => 'example.com',
            'title' => 'Fallback page',
            'html' => '<p>Ghost content</p>',
            'canonical_url' => 'https://example.com/about',
        ]);
        $request = Request::create('https://example.com/about');
        $route = new RoutingRoute(['GET'], '/about', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);
        $controller = new GhostController(new NullEnricher, $content);

        $view = $controller->page($request);

        $this->assertSame('multidomain-ghost::page', $view->name());
        $this->assertSame('Fallback page', $view->getData()['content']['title']);
        $this->assertSame('Fallback page', $view->getData()['seo']['title']);
    }

    public function test_post_webhook_dispatches_event(): void
    {
        Event::fake();

        $payload = [
            'name' => 'post.updated',
            'post' => [
                'current' => [
                    'canonical_url' => 'https://example.com/test-post',
                    'tags' => [['name' => '#post']],
                ],
            ],
        ];
        $response = $this->signedWebhook($payload);

        $response->assertStatus(200);

        Event::assertDispatched(GhostPostUpdated::class, function (GhostPostUpdated $event) {
            return $event->webhookName === 'post.updated'
                && $event->contentType === 'post'
                && in_array('example.com', $event->domains, true);
        });
    }

    public function test_post_webhook_rejects_an_invalid_ghost_signature(): void
    {
        $response = $this->call(
            'POST',
            '/webhook/ghost/post',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GHOST_SIGNATURE' => 'sha256='.str_repeat('0', 64).', t='.(string) now()->getTimestampMs(),
            ],
            content: json_encode(['post' => ['current' => []]], JSON_THROW_ON_ERROR),
        );

        $response->assertForbidden();
    }

    public function test_post_webhook_rejects_unsigned_requests_by_default(): void
    {
        $this->app['config']->set('multidomain-ghost.webhook_secret', '');

        $this->postJson('/webhook/ghost/post', [
            'post' => ['current' => []],
        ])->assertForbidden();
    }

    public function test_page_webhook_works_without_a_domain_allowlist(): void
    {
        Event::fake();
        $this->app['config']->set('multidomain-ghost.domains', []);
        $this->app['config']->set('domain.domains', []);

        $response = $this->signedWebhook([
            'name' => 'page.published',
            'page' => [
                'current' => [
                    'canonical_url' => 'https://another-example.com/about',
                    'tags' => [],
                ],
            ],
        ]);

        $response->assertOk()->assertJsonPath('content_type', 'page');
        Event::assertDispatched(
            GhostPostUpdated::class,
            fn (GhostPostUpdated $event): bool => $event->domains === ['another-example.com'],
        );
    }

    public function test_package_registers_only_the_ghost_webhook_route(): void
    {
        $this->assertSame(
            '/webhook/ghost/post',
            route('multidomain-ghost.webhook', absolute: false),
        );
        $this->assertFalse(Route::has('multidomain-ghost.example_com.robots'));
        $this->assertFalse(Route::has('multidomain-ghost.example_com.sitemap'));
        $this->assertFalse(Route::has('multidomain-ghost.example_com.feed'));
    }

    public function test_sitemap_returns_xml_from_normalized_links(): void
    {
        $content = $this->createMock(GhostContentService::class);
        $content->method('domain')->willReturn('example.com');
        $content->method('slugs')->willReturn([
            [
                'canonical_url' => 'https://example.com/indexed',
                'slug' => 'indexed',
                'updated_at' => '2026-07-25T10:00:00+00:00',
                'published_at' => '2026-07-24T10:00:00+00:00',
            ],
            [
                'canonical_url' => 'https://example.com/private',
                'codeinjection_head' => '<meta name="robots" content="noindex">',
            ],
            [
                'canonical_url' => 'https://example.com/{placeholder}',
            ],
        ]);

        $controller = new GhostController(new NullEnricher, $content);

        $this->assertSame([
            [
                'url' => 'https://example.com/indexed',
                'slug' => 'indexed',
                'updated_at' => '2026-07-25T10:00:00+00:00',
                'published_at' => '2026-07-24T10:00:00+00:00',
            ],
        ], $controller->sitemapLinks());

        $response = $controller->sitemap();

        $this->assertSame('application/xml; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            '<loc>https://example.com/indexed</loc>',
            $response->getContent(),
        );
        $this->assertStringContainsString(
            '<lastmod>2026-07-25T10:00:00+00:00</lastmod>',
            $response->getContent(),
        );
        $this->assertStringNotContainsString('private', $response->getContent());
    }

    public function test_feed_returns_rss_from_normalized_data(): void
    {
        $dataBlog = [
            'posts' => [[
                'title' => 'Example & Post',
                'canonical_url' => 'https://example.com/post?source=feed&lang=en',
                'excerpt' => 'A short description.',
                'published_at' => '2026-07-24T10:00:00+00:00',
            ]],
            'meta' => ['pagination' => ['page' => 1]],
        ];
        $content = $this->createMock(GhostContentService::class);
        $content->method('domain')->willReturn('example.com');
        $content->expects($this->exactly(2))
            ->method('dataBlog')
            ->with(1, 15)
            ->willReturn($dataBlog);

        $controller = new GhostController(new NullEnricher, $content);
        $request = Request::create('/feed', 'GET', ['page' => 0]);

        $this->assertSame([
            'domain' => 'example.com',
            'dataBlog' => $dataBlog,
            'page' => 1,
        ], $controller->feedData($request));
        $response = $controller->feed($request);

        $this->assertSame('application/rss+xml; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<rss version="2.0">', $response->getContent());
        $this->assertStringContainsString('<title>Example &amp; Post</title>', $response->getContent());
        $this->assertStringContainsString(
            '<link>https://example.com/post?source=feed&amp;lang=en</link>',
            $response->getContent(),
        );
    }

    public function test_blog_passes_post_listing_data_to_the_fallback_view(): void
    {
        $dataBlog = [
            'posts' => [[
                'title' => 'First post',
                'canonical_url' => 'https://example.com/blog/first-post',
            ]],
        ];
        $content = $this->createMock(GhostContentService::class);
        $content->method('domain')->willReturn('example.com');
        $content->expects($this->once())
            ->method('dataBlog')
            ->with(2, 15)
            ->willReturn($dataBlog);
        $content->expects($this->once())
            ->method('getPost')
            ->with('https://example.com/blog')
            ->willReturn(null);
        $request = Request::create('https://example.com/blog?page=2');
        $route = new RoutingRoute(['GET'], '/blog', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);
        $controller = new GhostController(new NullEnricher, $content);

        $view = $controller->blog($request);

        $this->assertSame('multidomain-ghost::blog', $view->name());
        $this->assertSame($dataBlog, $view->getData()['dataBlog']);
        $this->assertSame(2, $view->getData()['page']);
    }

    private function signedWebhook(array $payload)
    {
        $content = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestampMs();
        $signature = hash_hmac('sha256', $content.$timestamp, 'secret123');

        return $this->call(
            'POST',
            '/webhook/ghost/post',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GHOST_SIGNATURE' => "sha256={$signature}, t={$timestamp}",
            ],
            content: $content,
        );
    }
}
