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
                $paths = config('multidomain-ghost.routes.paths');
                if (!is_array($paths)) {
                    $paths = [
                        'home' => '/',
                        'sitemap' => '/sitemap.xml',
                        'feed' => '/feed',
                        'robots' => '/robots.txt',
                        'blog' => '/blog',
                        'post' => '/blog/{slug}',
                        'ads' => null,
                    ];
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

                $adsPath = $paths['ads'] ?? null;
                if ($adsPath === null) {
                    $adsTxt = (string) (config('multidomain-ghost.ads.txt') ?: config('services.adsense.ads_txt', ''));
                    if (trim($adsTxt) !== '') {
                        $adsPath = '/ads.txt';
                    }
                }
                
                if (is_string($adsPath)) {
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
}
