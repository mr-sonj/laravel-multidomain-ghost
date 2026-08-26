<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Routing;

use Closure;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Support\Facades\Route;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;
use MrSonj\MultiDomainGhost\Http\Middleware\EnsureRegisteredDomain;
use MrSonj\MultiDomainGhost\Support\DomainName;
use MrSonj\MultiDomainGhost\Support\DomainRegistry;

class GhostRouteRegistrar
{
    /**
     * Paths used when the published config predates the routes.paths map.
     */
    private const DEFAULT_PATHS = [
        'home' => '/',
        'sitemap' => '/sitemap.xml',
        'feed' => '/feed',
        'robots' => '/robots.txt',
        'blog' => '/blog',
        'post' => '/blog/{slug}',
        'ads' => null,
    ];

    private static ?RouteCollectionInterface $routeCollection = null;

    /**
     * @var array<string, bool>
     */
    private static array $registeredDomains = [];

    /**
     * Register ghost routes for a specific domain.
     */
    public static function registerDomain(string $domain, ?Closure $routes = null): void
    {
        $domain = DomainName::normalize($domain);
        if ($domain === '') {
            return;
        }

        $routeCollection = Route::getRoutes();
        if (self::$routeCollection !== $routeCollection) {
            self::$routeCollection = $routeCollection;
            self::$registeredDomains = [];
        }

        $middleware = (array) config('multidomain-ghost.routes.middleware', ['web']);
        $routeNamePrefix = str_replace(['.', '-'], '_', $domain);

        if (isset(self::$registeredDomains[$domain])) {
            if ($routes instanceof Closure) {
                Route::domain($domain)
                    ->middleware($middleware)
                    ->group($routes);

                // The catch-all this domain already owns now sits before the routes
                // that closure just added, so it would swallow every one of them.
                self::moveCatchAllLast("{$routeNamePrefix}_catch_all");
            }

            return;
        }

        self::$registeredDomains[$domain] = true;

        $sanitized = DomainName::dirKey($domain);

        Route::domain($domain)
            ->middleware($middleware)
            ->group(function () use ($sanitized, $routeNamePrefix, $routes) {
                $paths = config('multidomain-ghost.routes.paths');
                if (! is_array($paths)) {
                    $paths = self::DEFAULT_PATHS;
                }

                if (isset($paths['robots']) && is_string($paths['robots'])) {
                    Route::name("{$routeNamePrefix}_robots")
                        ->get($paths['robots'], [GhostController::class, 'robots']);
                }

                if (isset($paths['sitemap']) && is_string($paths['sitemap'])) {
                    Route::name("{$routeNamePrefix}_sitemap")
                        ->get($paths['sitemap'], [GhostController::class, 'sitemap']);
                }

                if (isset($paths['feed']) && is_string($paths['feed'])) {
                    Route::name("{$routeNamePrefix}_feed")
                        ->get($paths['feed'], [GhostController::class, 'feed']);
                }

                // null means "wherever ads.txt normally lives"; the content check
                // applies to an explicit path too, so no configuration can produce
                // an empty ads.txt served with a 200.
                $adsPath = $paths['ads'] ?? '/ads.txt';

                if (is_string($adsPath) && self::adsTxtContent() !== '') {
                    Route::name("{$routeNamePrefix}_ads")
                        ->get($adsPath, [GhostController::class, 'ads']);
                }

                if (isset($paths['home']) && is_string($paths['home'])) {
                    Route::name("{$routeNamePrefix}_home")
                        ->get($paths['home'], [GhostController::class, 'page'])
                        ->defaults('viewPath', "{$sanitized}/home");
                }

                if (isset($paths['blog']) && is_string($paths['blog'])) {
                    Route::name("{$routeNamePrefix}_blog")
                        ->get($paths['blog'], [GhostController::class, 'blog'])
                        ->defaults('viewPath', "{$sanitized}/blog");
                }

                if (isset($paths['post']) && is_string($paths['post'])) {
                    Route::name("{$routeNamePrefix}_post")
                        ->get($paths['post'], [GhostController::class, 'page'])
                        ->defaults('viewPath', "{$sanitized}/post")
                        ->where('slug', '[A-Za-z0-9\-_]+');
                }

                if ($routes instanceof Closure) {
                    $routes();
                }

                if ((bool) config('multidomain-ghost.routes.catch_all', false)) {
                    Route::name("{$routeNamePrefix}_catch_all")
                        ->get('/{path}', [GhostController::class, 'page'])
                        ->defaults('viewPath', "{$sanitized}/page")
                        ->where('path', '.*');
                }
            });

        if ((bool) config('multidomain-ghost.routes.redirect_www', true)) {
            Route::domain("www.{$domain}")
                ->middleware(EnsureRegisteredDomain::class)
                ->group(function () use ($domain, $routeNamePrefix) {
                    Route::name("{$routeNamePrefix}_www_redirect")
                        ->get('/{path?}', function (?string $path = null) use ($domain) {
                            return redirect()->away("https://{$domain}/".ltrim((string) $path, '/'), 301);
                        })->where('path', '.*');
                });
        }
    }

    /**
     * Register ghost routes for all registered domains.
     */
    public static function registerAll(): void
    {
        foreach (DomainRegistry::all() as $domain) {
            self::registerDomain($domain);
        }
    }

    /**
     * Reset tracked registered domains.
     */
    public static function flush(): void
    {
        self::$routeCollection = null;
        self::$registeredDomains = [];
    }

    /**
     * Resolved ads.txt body.
     *
     * An empty body means the route must not exist at all: a 200 with an empty
     * ads.txt reads as "this domain authorises no sellers", which is not the same
     * claim as having no ads.txt file.
     */
    private static function adsTxtContent(): string
    {
        $ads = config('multidomain-ghost.ads.txt') ?: config('services.adsense.ads_txt', '');

        return trim((string) $ads);
    }

    /**
     * Re-append a catch-all route so it stays the last thing that can match.
     *
     * A RouteCollection cannot reorder in place, so this rebuilds it with the
     * catch-all moved to the end.
     */
    private static function moveCatchAllLast(string $routeName): void
    {
        $router = Route::getFacadeRoot();
        $existing = $router->getRoutes();

        if (! $existing instanceof RouteCollection) {
            return;
        }

        $catchAll = $existing->getByName($routeName);
        if ($catchAll === null) {
            return;
        }

        $reordered = new RouteCollection;

        foreach ($existing->getRoutes() as $route) {
            if ($route !== $catchAll) {
                $reordered->add($route);
            }
        }

        $reordered->add($catchAll);

        $router->setRoutes($reordered);

        // setRoutes() swaps in a new collection instance; without this the next
        // registerDomain() would read it as a fresh one and re-register everything.
        self::$routeCollection = $router->getRoutes();
    }
}
