<?php

namespace MrSonj\MultiDomainGhost\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use MrSonj\MultiDomainGhost\Contracts\DomainEnricherInterface;
use MrSonj\MultiDomainGhost\Events\GhostPostUpdated;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostWebhookController;
use MrSonj\MultiDomainGhost\Http\Middleware\EnsureRegisteredDomain;
use MrSonj\MultiDomainGhost\Services\GhostCacheManager;
use MrSonj\MultiDomainGhost\Services\GhostContentService;
use MrSonj\MultiDomainGhost\Support\NullEnricher;
use MrSonj\MultiDomainGhost\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GhostControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setRegisteredDomains(['example_com' => []]);
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app->bind(DomainEnricherInterface::class, NullEnricher::class);
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

    public function test_seo_data_identifies_page_via_hash_page_tag(): void
    {
        /** @var GhostController $controller */
        $controller = $this->app->make(GhostController::class);

        $seo = $controller->seoData([
            'domain' => 'example.com',
            'title' => 'About Us',
            'tags' => [['name' => '#page', 'slug' => 'hash-page']],
        ]);

        $this->assertTrue($seo['is_page']);
        $this->assertSame('WebPage', $seo['type']);
        $this->assertSame('website', $seo['og']['type']);
    }

    public function test_seo_data_defaults_to_article_without_hash_page_tag(): void
    {
        /** @var GhostController $controller */
        $controller = $this->app->make(GhostController::class);

        $seo = $controller->seoData([
            'domain' => 'example.com',
            'title' => 'My First Post',
            'tags' => [['name' => '#news', 'slug' => 'hash-news']],
        ]);

        $this->assertFalse($seo['is_page']);
        $this->assertSame('Article', $seo['type']);
        $this->assertSame('article', $seo['og']['type']);
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

    public function test_ghost_routes_are_guarded_by_the_file_registry(): void
    {
        $content = $this->createMock(GhostContentService::class);
        $content->method('domain')->willReturn('example.com');
        $controller = new GhostController(new NullEnricher, $content);

        $this->assertContains(
            EnsureRegisteredDomain::class,
            array_column($controller->getMiddleware(), 'middleware'),
        );
    }

    public function test_an_existing_ghost_route_returns_404_after_its_config_file_is_removed(): void
    {
        Route::domain('removed.example.com')->get(
            '/guarded-domain',
            [GhostController::class, 'robots'],
        );
        $this->setRegisteredDomains([]);

        $this->get('https://removed.example.com/guarded-domain')->assertNotFound();
    }

    public function test_post_webhook_delegates_to_the_webhook_controller(): void
    {
        $content = $this->createMock(GhostContentService::class);
        $content->method('domain')->willReturn('example.com');

        $webhookController = $this->createMock(GhostWebhookController::class);
        $webhookController->expects($this->once())
            ->method('__invoke')
            ->willReturn(new JsonResponse(['ok' => true]));

        $this->app->instance(GhostWebhookController::class, $webhookController);

        $controller = new GhostController(new NullEnricher, $content);
        $response = $controller->postWebhook(
            Request::create('/webhook'),
            $this->createMock(GhostCacheManager::class)
        );

        $this->assertTrue($response->getData()->ok);
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

    public function test_post_webhook_ignores_every_domain_when_registry_is_empty(): void
    {
        Event::fake();
        $this->setRegisteredDomains([]);

        $response = $this->signedWebhook([
            'name' => 'post.published',
            'post' => [
                'current' => [
                    'canonical_url' => 'https://another-example.com/about',
                    'tags' => [['name' => '#page', 'slug' => 'hash-page']],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Ignored. Domain not registered in this application.')
            ->assertJsonPath('cache_cleared', []);
        Event::assertNotDispatched(GhostPostUpdated::class);
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
        $this->assertStringContainsString('<rss version="2.0"', $response->getContent());
        $this->assertStringContainsString('<title>Example &amp; Post</title>', $response->getContent());
        $this->assertStringContainsString(
            '<link>https://example.com/post?source=feed&amp;lang=en</link>',
            $response->getContent(),
        );
    }

    public function test_feed_declares_the_elements_readers_expect(): void
    {
        $content = $this->createMock(GhostContentService::class);
        $content->method('domain')->willReturn('example.com');
        $content->method('dataBlog')->willReturn(['posts' => [[
            'canonical_url' => 'https://example.com/post',
            'title' => 'A post',
            'published_at' => '2026-01-02T03:04:05.000Z',
        ]]]);

        $xml = (new GhostController(new NullEnricher, $content))
            ->feed(Request::create('https://example.com/feed'))
            ->getContent();

        $this->assertStringContainsString('xmlns:atom="http://www.w3.org/2005/Atom"', $xml);
        $this->assertStringContainsString(
            '<atom:link href="https://example.com/feed" rel="self" type="application/rss+xml"/>',
            $xml,
        );
        $this->assertStringContainsString('<language>', $xml);
        $this->assertStringContainsString('<lastBuildDate>', $xml);
    }

    public function test_blog_404s_on_an_out_of_range_page_number(): void
    {
        $this->app['config']->set('multidomain-ghost.max_blog_page', 50);

        $requestedPages = [];
        $content = $this->createMock(GhostContentService::class);
        $content->method('domain')->willReturn('example.com');
        $content->method('getPost')->willReturn(null);
        $content->method('dataBlog')->willReturnCallback(function (int $page) use (&$requestedPages) {
            $requestedPages[] = $page;

            return ['posts' => []];
        });

        $request = Request::create('https://example.com/blog?page=999999');
        $route = new RoutingRoute(['GET'], '/blog', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);

        $this->expectException(NotFoundHttpException::class);

        try {
            (new GhostController(new NullEnricher, $content))->blog($request);
        } finally {
            $this->assertSame([], $requestedPages, 'blog() must not reach Ghost for an out-of-range page');
        }
    }

    public function test_blog_serves_a_page_inside_the_supported_range(): void
    {
        $this->app['config']->set('multidomain-ghost.max_blog_page', 50);

        $requestedPages = [];
        $content = $this->createMock(GhostContentService::class);
        $content->method('domain')->willReturn('example.com');
        $content->method('getPost')->willReturn(null);
        $content->method('dataBlog')->willReturnCallback(function (int $page) use (&$requestedPages) {
            $requestedPages[] = $page;

            return ['posts' => []];
        });

        $request = Request::create('https://example.com/blog?page=50');
        $route = new RoutingRoute(['GET'], '/blog', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);

        $view = (new GhostController(new NullEnricher, $content))->blog($request);

        $this->assertSame([50], $requestedPages);
        $this->assertSame(50, $view->getData()['page']);
    }

    public function test_blog_treats_a_junk_page_value_as_the_first_page(): void
    {
        $requestedPages = [];
        $content = $this->createMock(GhostContentService::class);
        $content->method('domain')->willReturn('example.com');
        $content->method('getPost')->willReturn(null);
        $content->method('dataBlog')->willReturnCallback(function (int $page) use (&$requestedPages) {
            $requestedPages[] = $page;

            return ['posts' => []];
        });

        $request = Request::create('https://example.com/blog?page=-3');
        $route = new RoutingRoute(['GET'], '/blog', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);

        $view = (new GhostController(new NullEnricher, $content))->blog($request);

        $this->assertSame([1], $requestedPages);
        $this->assertSame(1, $view->getData()['page']);
    }

    public function test_feed_data_404s_on_an_out_of_range_page_number(): void
    {
        $this->app['config']->set('multidomain-ghost.max_blog_page', 50);

        $requestedPages = [];
        $content = $this->createMock(GhostContentService::class);
        $content->method('domain')->willReturn('example.com');
        $content->method('dataBlog')->willReturnCallback(function (int $page) use (&$requestedPages) {
            $requestedPages[] = $page;

            return ['posts' => []];
        });

        $this->expectException(NotFoundHttpException::class);

        try {
            (new GhostController(new NullEnricher, $content))
                ->feedData(Request::create('/feed', 'GET', ['page' => 999999]));
        } finally {
            $this->assertSame([], $requestedPages, 'feedData() must not reach Ghost for an out-of-range page');
        }
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
