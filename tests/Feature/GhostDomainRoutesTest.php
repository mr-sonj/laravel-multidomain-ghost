<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Tests\Feature;

use Illuminate\Http\Request;
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
        config()->set('multidomain-ghost.ads.txt', 'test');
        Route::ghostDomain('example.com');

        $this->assertTrue(Route::has('example_com_robots'));
        $this->assertTrue(Route::has('example_com_sitemap'));
        $this->assertTrue(Route::has('example_com_ads'));
        $this->assertTrue(Route::has('example_com_www_redirect'));

        $this->assertFalse(Route::has('example_com_home'));
        $this->assertFalse(Route::has('example_com_blog'));
        $this->assertFalse(Route::has('example_com_post'));
        $this->assertFalse(Route::has('example_com_feed'));
    }

    public function test_macro_handles_domains_with_hyphens(): void
    {
        config()->set('multidomain-ghost.ads.txt', 'test');
        Route::ghostDomain('my-sample-blog.co.uk');

        $this->assertTrue(Route::has('my_sample_blog_co_uk_robots'));
        $this->assertTrue(Route::has('my_sample_blog_co_uk_ads'));
        $this->assertTrue(Route::has('my_sample_blog_co_uk_www_redirect'));
    }

    public function test_macro_accepts_custom_routes_closure(): void
    {
        Route::ghostDomain('custom.com', function () {
            Route::name('custom_deal')->get('/special-deal', fn () => 'deal');
        });

        $this->assertTrue(Route::has('custom_deal'));

        $customRoute = Route::getRoutes()->getByName('custom_deal');
        $this->assertNotNull($customRoute);
        $this->assertSame('custom.com', $customRoute->getDomain());
    }

    public function test_macro_skips_www_redirect_when_config_is_disabled(): void
    {
        config()->set('multidomain-ghost.routes.redirect_www', false);

        Route::ghostDomain('no-www.com');

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

    public function test_register_all_registers_routes_for_all_discovered_domains(): void
    {
        $this->setRegisteredDomains([
            'site_one_com' => [],
            'site_two_com' => [],
        ]);

        GhostRouteRegistrar::registerAll();

        $this->assertTrue(Route::has('site_one_com_robots'));
        $this->assertTrue(Route::has('site_two_com_robots'));
    }

    public function test_macro_ignores_empty_or_invalid_domain(): void
    {
        Route::ghostDomain('');

        $this->assertFalse(Route::has('_robots'));
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

        if (! $this->app->routesAreCached()) {
            $this->app['files']->put($this->app->getCachedRoutesPath(), '<?php return [];');
        }

        $this->assertTrue($this->app->routesAreCached());

        $this->app['router']->setRoutes(new RouteCollection);

        (new MultiDomainGhostServiceProvider($this->app))->boot();

        $this->assertFalse(Route::has('cached_example_com_robots'));
        $this->assertFalse(Route::has('multidomain-ghost.webhook'));
        $this->assertCount(0, Route::getRoutes());

        if ($this->app['files']->exists($this->app->getCachedRoutesPath())) {
            $this->app['files']->delete($this->app->getCachedRoutesPath());
        }
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
        $this->assertTrue(Route::has('extend_com_robots'));
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
        $this->assertTrue(Route::has('fresh_example_com_robots'));
    }

    public function test_ads_txt_route_is_not_registered_when_config_is_empty(): void
    {
        config()->set('multidomain-ghost.ads.txt', '');
        config()->set('services.adsense.ads_txt', '');

        $this->setRegisteredDomains(['example_com' => []]);
        GhostRouteRegistrar::registerAll();

        $this->get('https://example.com/ads.txt')->assertNotFound();
    }

    public function test_ads_txt_route_is_registered_when_package_config_is_present(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('multidomain-ghost.ads.txt', 'google.com, pub-123, DIRECT');
        config()->set('services.adsense.ads_txt', '');

        $this->setRegisteredDomains(['example_com' => []]);
        GhostRouteRegistrar::registerAll();

        $response = $this->get('https://example.com/ads.txt');

        $response->assertOk();
        $response->assertSee('google.com, pub-123, DIRECT', false);
        $this->assertSame('text/plain;charset=UTF-8', $response->headers->get('Content-Type'));
    }

    public function test_ads_txt_route_is_registered_when_legacy_config_is_present(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('multidomain-ghost.ads.txt', '');
        config()->set('services.adsense.ads_txt', 'google.com, pub-456, DIRECT');

        $this->setRegisteredDomains(['example_com' => []]);
        GhostRouteRegistrar::registerAll();

        $response = $this->get('https://example.com/ads.txt');

        $response->assertOk();
        $response->assertSee('google.com, pub-456, DIRECT', false);
        $this->assertSame('text/plain;charset=UTF-8', $response->headers->get('Content-Type'));
    }

    public function test_catch_all_route_is_registered_after_custom_closure_when_enabled(): void
    {
        config()->set('multidomain-ghost.routes.catch_all', true);

        Route::ghostDomain('catch-all.com', function () {
            Route::name('catch_all_com_custom')->get('/custom', fn () => 'custom');
        });

        GhostRouteRegistrar::registerCatchAlls();

        $this->assertTrue(Route::has('catch_all_com_catch_all'));

        $catchAllRoute = Route::getRoutes()->getByName('catch_all_com_catch_all');
        $this->assertNotNull($catchAllRoute);
        $this->assertSame('catch-all.com', $catchAllRoute->getDomain());
        $this->assertSame('catch-all_com/page', $catchAllRoute->defaults['viewPath']);
        $this->assertSame(GhostController::class.'@page', ltrim($catchAllRoute->getActionName(), '\\'));

        // Verify that custom route comes before catch-all in route collection
        $routeNames = array_map(fn ($r) => $r->getName(), Route::getRoutes()->getRoutes());
        $customIndex = array_search('catch_all_com_custom', $routeNames, true);
        $catchAllIndex = array_search('catch_all_com_catch_all', $routeNames, true);

        $this->assertNotFalse($customIndex);
        $this->assertNotFalse($catchAllIndex);
        $this->assertLessThan($catchAllIndex, $customIndex);
    }

    public function test_ads_txt_route_is_not_registered_when_an_explicit_path_has_no_content(): void
    {
        config()->set('multidomain-ghost.routes.paths.ads', '/ads.txt');
        config()->set('multidomain-ghost.ads.txt', '');
        config()->set('services.adsense.ads_txt', '');

        $this->setRegisteredDomains(['example_com' => []]);
        GhostRouteRegistrar::registerAll();

        $this->assertFalse(Route::has('example_com_ads'));
        $this->get('https://example.com/ads.txt')->assertNotFound();
    }

    public function test_ads_txt_route_honours_an_explicit_path_when_content_is_present(): void
    {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('multidomain-ghost.routes.paths.ads', '/app-ads.txt');
        config()->set('multidomain-ghost.ads.txt', 'google.com, pub-789, DIRECT');

        $this->setRegisteredDomains(['example_com' => []]);
        GhostRouteRegistrar::registerAll();

        $this->assertSame('app-ads.txt', Route::getRoutes()->getByName('example_com_ads')->uri());
        $this->get('https://example.com/app-ads.txt')->assertOk()->assertSee('pub-789', false);
    }

    public function test_a_path_set_to_null_disables_only_that_route(): void
    {
        config()->set('multidomain-ghost.routes.paths.sitemap', null);
        // ads.txt is registered only when it has a body, so give it one: without this
        // the loop below would pass for the wrong reason.
        config()->set('multidomain-ghost.ads.txt', 'google.com, pub-123, DIRECT');

        Route::ghostDomain('example.com');

        $this->assertFalse(Route::has('example_com_sitemap'));

        foreach (['robots', 'ads'] as $name) {
            $this->assertTrue(Route::has("example_com_{$name}"));
        }
    }

    public function test_a_relocated_path_keeps_its_route_name_and_view_path(): void
    {
        config()->set('multidomain-ghost.routes.paths.sitemap', '/sitemap-index.xml');

        Route::ghostDomain('example.com');

        $sitemap = Route::getRoutes()->getByName('example_com_sitemap');
        $this->assertSame('sitemap-index.xml', $sitemap->uri());
    }

    public function test_catch_all_does_not_swallow_routes_added_by_a_later_ghost_domain_call(): void
    {
        config()->set('multidomain-ghost.routes.catch_all', true);

        GhostRouteRegistrar::registerDomain('example.com');

        Route::ghostDomain('example.com', function () {
            Route::name('example_com_pricing')->get('/pricing', fn () => 'pricing');
        });

        GhostRouteRegistrar::registerCatchAlls();

        $this->assertSame('example_com_pricing', $this->matchedRouteName('https://example.com/pricing'));
        $this->assertSame('example_com_catch_all', $this->matchedRouteName('https://example.com/anything-else'));
    }

    public function test_domain_route_file_is_loaded_inside_the_domain_group(): void
    {
        $this->setRegisteredDomains(['example_com' => []]);
        $this->setDomainRouteFiles([
            'example_com.php' => <<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
Route::get('/pricing', fn () => 'pricing')->name('example_com_pricing');
PHP
        ]);

        (new MultiDomainGhostServiceProvider($this->app))->boot();

        Route::getRoutes()->refreshNameLookups();

        $pricingRoute = Route::getRoutes()->getByName('example_com_pricing');
        $this->assertNotNull($pricingRoute);
        $this->assertSame('example.com', $pricingRoute->getDomain());
        $this->assertContains('web', $pricingRoute->middleware());
    }

    public function test_a_missing_domain_route_file_is_ignored(): void
    {
        $this->setRegisteredDomains(['example_com' => []]);

        // Boot shouldn't throw an error when missing example_com.php
        (new MultiDomainGhostServiceProvider($this->app))->boot();

        $this->assertTrue(Route::has('example_com_robots'));
    }

    public function test_catch_all_is_registered_after_routes_declared_post_boot(): void
    {
        $this->setRegisteredDomains(['example_com' => []]);
        config()->set('multidomain-ghost.routes.catch_all', true);

        GhostRouteRegistrar::registerAll();

        // Add a route loose
        Route::domain('example.com')->get('/about', fn () => 'about')->name('example_com_about');

        GhostRouteRegistrar::registerCatchAlls();

        $this->assertSame('example_com_about', $this->matchedRouteName('https://example.com/about'));
        $this->assertSame('example_com_catch_all', $this->matchedRouteName('https://example.com/anything-else'));
    }

    public function test_register_all_leaves_the_catch_all_to_the_booted_phase(): void
    {
        $this->setRegisteredDomains(['example_com' => []]);
        config()->set('multidomain-ghost.routes.catch_all', true);

        GhostRouteRegistrar::registerAll();

        // routes/web.php has not been loaded at this point - the application's route
        // provider is registered from a booting callback, so it boots after this
        // package does. A catch-all registered here would shadow every route in it.
        $this->assertFalse(Route::has('example_com_catch_all'));

        GhostRouteRegistrar::registerCatchAlls();

        $this->assertTrue(Route::has('example_com_catch_all'));
    }

    public function test_catch_all_is_not_duplicated_when_registered_twice(): void
    {
        $this->setRegisteredDomains(['example_com' => []]);
        config()->set('multidomain-ghost.routes.catch_all', true);

        GhostRouteRegistrar::registerAll();
        GhostRouteRegistrar::registerCatchAlls();

        $before = count(Route::getRoutes()->getRoutes());
        GhostRouteRegistrar::registerCatchAlls();
        $after = count(Route::getRoutes()->getRoutes());

        $this->assertSame($before, $after);
    }

    public function test_catch_all_covers_domains_registered_only_through_the_macro(): void
    {
        config()->set('multidomain-ghost.routes.catch_all', true);

        Route::ghostDomain('macro.com');
        GhostRouteRegistrar::registerCatchAlls();

        $this->assertTrue(Route::has('macro_com_catch_all'));
    }

    public function test_ads_path_set_to_null_disables_the_route(): void
    {
        config()->set('multidomain-ghost.routes.paths.ads', null);
        config()->set('multidomain-ghost.ads.txt', 'google.com, pub-123, DIRECT');

        Route::ghostDomain('example.com');

        $this->assertFalse(Route::has('example_com_ads'));
    }

    private function matchedRouteName(string $url): ?string
    {
        return Route::getRoutes()
            ->match(Request::create($url))
            ->getName();
    }
}
