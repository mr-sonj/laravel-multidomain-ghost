<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Tests\Feature;

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use MrSonj\MultiDomainGhost\Contracts\DomainEnricherInterface;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;
use MrSonj\MultiDomainGhost\Http\Middleware\EnsureRegisteredDomain;
use MrSonj\MultiDomainGhost\MultiDomainGhostServiceProvider;
use MrSonj\MultiDomainGhost\Routing\GhostRouteRegistrar;
use MrSonj\MultiDomainGhost\Support\NullEnricher;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class GhostDomainRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        GhostRouteRegistrar::flush();
        $this->app->bind(DomainEnricherInterface::class, NullEnricher::class);
    }

    protected function tearDown(): void
    {
        GhostRouteRegistrar::flush();

        parent::tearDown();
    }

    public function test_macro_registers_all_ghost_routes_for_domain(): void
    {
        Route::ghostDomain('example.com');

        $this->assertTrue(Route::has('example_com_robots'));
        $this->assertTrue(Route::has('example_com_sitemap'));
        $this->assertTrue(Route::has('example_com_feed'));
        $this->assertTrue(Route::has('example_com_home'));
        $this->assertTrue(Route::has('example_com_blog'));
        $this->assertTrue(Route::has('example_com_post'));
        $this->assertTrue(Route::has('example_com_www_redirect'));

        $homeRoute = Route::getRoutes()->getByName('example_com_home');
        $this->assertNotNull($homeRoute);
        $this->assertSame('example.com', $homeRoute->getDomain());
        $this->assertSame(GhostController::class.'@page', ltrim($homeRoute->getActionName(), '\\'));

        $blogRoute = Route::getRoutes()->getByName('example_com_blog');
        $this->assertNotNull($blogRoute);
        $this->assertSame('example_com/blog', $blogRoute->defaults['viewPath']);
        $this->assertSame(GhostController::class.'@blog', ltrim($blogRoute->getActionName(), '\\'));

        $postRoute = Route::getRoutes()->getByName('example_com_post');
        $this->assertNotNull($postRoute);
        $this->assertSame('example_com/post', $postRoute->defaults['viewPath']);
        $this->assertSame(GhostController::class.'@page', ltrim($postRoute->getActionName(), '\\'));
    }

    public function test_macro_handles_domains_with_hyphens(): void
    {
        Route::ghostDomain('my-sample-blog.co.uk');

        $this->assertTrue(Route::has('my_sample_blog_co_uk_robots'));
        $this->assertTrue(Route::has('my_sample_blog_co_uk_home'));
        $this->assertTrue(Route::has('my_sample_blog_co_uk_blog'));
        $this->assertTrue(Route::has('my_sample_blog_co_uk_post'));
        $this->assertTrue(Route::has('my_sample_blog_co_uk_www_redirect'));

        $homeRoute = Route::getRoutes()->getByName('my_sample_blog_co_uk_home');
        $this->assertNotNull($homeRoute);
        $this->assertSame('my-sample-blog.co.uk', $homeRoute->getDomain());
        $this->assertSame('my-sample-blog_co_uk/home', $homeRoute->defaults['viewPath']);
    }

    public function test_macro_accepts_custom_routes_closure(): void
    {
        Route::ghostDomain('custom.com', function () {
            Route::name('custom_deal')->get('/special-deal', fn () => 'deal');
        });

        $this->assertTrue(Route::has('custom_com_home'));
        $this->assertTrue(Route::has('custom_deal'));

        $customRoute = Route::getRoutes()->getByName('custom_deal');
        $this->assertNotNull($customRoute);
        $this->assertSame('custom.com', $customRoute->getDomain());
    }

    public function test_macro_skips_www_redirect_when_config_is_disabled(): void
    {
        config()->set('multidomain-ghost.routes.redirect_www', false);

        Route::ghostDomain('no-www.com');

        $this->assertTrue(Route::has('no_www_com_home'));
        $this->assertFalse(Route::has('no_www_com_www_redirect'));
    }

    public function test_www_redirect_redirects_to_apex_with_path(): void
    {
        $this->setRegisteredDomains(['example_com' => []]);
        Route::ghostDomain('example.com');

        $response = $this->get('https://www.example.com/some/nested/page');

        $response->assertStatus(301);
        $response->assertRedirect('https://example.com/some/nested/page');
    }

    public function test_www_redirect_rejects_a_domain_missing_from_the_registry(): void
    {
        Route::ghostDomain('removed.example.com');

        $route = Route::getRoutes()->getByName('removed_example_com_www_redirect');
        $this->assertNotNull($route);
        $this->assertContains(EnsureRegisteredDomain::class, $route->middleware());

        $this->get('https://www.removed.example.com/some/path')->assertNotFound();
    }

    public function test_provider_auto_registers_domain_routes_with_web_middleware(): void
    {
        $this->setRegisteredDomains(['auto_example_com' => []]);

        (new MultiDomainGhostServiceProvider($this->app))->boot();

        $route = Route::getRoutes()->getByName('auto_example_com_home');
        $this->assertNotNull($route);
        $this->assertContains('web', $route->middleware());
    }

    public function test_register_all_registers_routes_for_all_discovered_domains(): void
    {
        $this->setRegisteredDomains([
            'site_one_com' => [],
            'site_two_com' => [],
        ]);

        GhostRouteRegistrar::registerAll();

        $this->assertTrue(Route::has('site_one_com_home'));
        $this->assertTrue(Route::has('site_two_com_home'));
    }

    public function test_macro_ignores_empty_or_invalid_domain(): void
    {
        Route::ghostDomain('');

        $this->assertFalse(Route::has('_home'));
    }

    public function test_macro_is_idempotent_for_the_same_domain(): void
    {
        Route::ghostDomain('idempotent.com');
        $initialCount = count(Route::getRoutes()->getRoutes());

        Route::ghostDomain('idempotent.com');
        $afterCount = count(Route::getRoutes()->getRoutes());

        $this->assertSame($initialCount, $afterCount);
    }

    public function test_provider_skips_route_registration_when_routes_are_cached(): void
    {
        $this->setRegisteredDomains(['cached_example_com' => []]);
        $this->app->instance('routes.cached', true);

        $this->assertTrue($this->app->routesAreCached());

        $this->app['router']->setRoutes(new RouteCollection);

        (new MultiDomainGhostServiceProvider($this->app))->boot();

        $this->assertFalse(Route::has('cached_example_com_home'));
        $this->assertFalse(Route::has('multidomain-ghost.webhook'));
        $this->assertCount(0, Route::getRoutes());
    }

    public function test_macro_does_not_duplicate_default_routes_when_extending_with_custom_routes(): void
    {
        Route::ghostDomain('extend.com');
        $initialCount = count(Route::getRoutes()->getRoutes());

        Route::ghostDomain('extend.com', function () {
            Route::name('extend_com_custom')->get('/custom', fn () => 'custom');
        });

        $afterCount = count(Route::getRoutes()->getRoutes());

        $this->assertSame($initialCount + 1, $afterCount);
        $this->assertTrue(Route::has('extend_com_custom'));
        $this->assertTrue(Route::has('extend_com_home'));
    }

    public function test_custom_routes_can_be_registered_after_auto_register_without_redefining_base_routes(): void
    {
        $this->setRegisteredDomains(['example_com' => []]);
        GhostRouteRegistrar::registerAll();

        $routesBefore = Route::getRoutes()->getRoutes();
        $this->assertNotEmpty($routesBefore);

        Route::ghostDomain('example.com', function () {
            Route::name('custom_page')->get('/custom-page', fn () => 'custom');
        });

        $routesAfter = Route::getRoutes()->getRoutes();

        $this->assertCount(count($routesBefore) + 1, $routesAfter);
        $this->assertTrue(Route::has('custom_page'));
    }

    public function test_register_all_registers_domains_again_for_a_replaced_route_collection(): void
    {
        $this->setRegisteredDomains(['fresh_example_com' => []]);
        GhostRouteRegistrar::registerAll();

        $initialDomainRouteCount = count(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn ($route): bool => in_array(
                $route->getDomain(),
                ['fresh.example.com', 'www.fresh.example.com'],
                true,
            ),
        ));

        $this->app['router']->setRoutes(new RouteCollection);
        GhostRouteRegistrar::registerAll();

        $this->assertGreaterThan(0, $initialDomainRouteCount);
        $this->assertCount($initialDomainRouteCount, Route::getRoutes());
        $this->assertTrue(Route::has('fresh_example_com_home'));
    }
}
