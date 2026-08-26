<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Routing;

use Closure;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Support\Facades\Route;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;
use MrSonj\MultiDomainGhost\Http\Middleware\EnsureRegisteredDomain;
use MrSonj\MultiDomainGhost\Support\DomainName;
use MrSonj\MultiDomainGhost\Support\DomainRegistry;

class GhostRouteRegistrar
{
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

        if (isset(self::$registeredDomains[$domain])) {
            if ($routes instanceof Closure) {
                Route::domain($domain)
                    ->middleware($middleware)
                    ->group($routes);
            }

            return;
        }

        self::$registeredDomains[$domain] = true;

        $sanitized = DomainName::dirKey($domain);
        $routeNamePrefix = str_replace(['.', '-'], '_', $domain);

        Route::domain($domain)
            ->middleware($middleware)
            ->group(function () use ($sanitized, $routeNamePrefix, $routes) {
                Route::name("{$routeNamePrefix}_robots")
                    ->get('/robots.txt', [GhostController::class, 'robots']);

                Route::name("{$routeNamePrefix}_sitemap")
                    ->get('/sitemap.xml', [GhostController::class, 'sitemap']);

                Route::name("{$routeNamePrefix}_feed")
                    ->get('/feed', [GhostController::class, 'feed']);

                $adsTxt = (string) (config('multidomain-ghost.ads.txt') ?: config('services.adsense.ads_txt', ''));
                if (trim($adsTxt) !== '') {
                    Route::name("{$routeNamePrefix}_ads")
                        ->get('/ads.txt', [GhostController::class, 'ads']);
                }

                Route::name("{$routeNamePrefix}_home")
                    ->get('/', [GhostController::class, 'page'])
                    ->defaults('viewPath', "{$sanitized}/home");

                Route::name("{$routeNamePrefix}_blog")
                    ->get('/blog', [GhostController::class, 'blog'])
                    ->defaults('viewPath', "{$sanitized}/blog");

                Route::name("{$routeNamePrefix}_post")
                    ->get('/blog/{slug}', [GhostController::class, 'page'])
                    ->defaults('viewPath', "{$sanitized}/post")
                    ->where('slug', '[A-Za-z0-9\-_]+');

                if ($routes instanceof Closure) {
                    $routes();
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
}
