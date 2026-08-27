<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Routing;

use Closure;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Support\Facades\Route;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;
use MrSonj\MultiDomainGhost\Http\Middleware\EnsureRegisteredDomain;
use MrSonj\MultiDomainGhost\Support\DomainAssets;
use MrSonj\MultiDomainGhost\Support\DomainName;
use MrSonj\MultiDomainGhost\Support\DomainRegistry;

class GhostRouteRegistrar
{
    /**
     * Paths used when the published config predates the routes.paths map.
     */
    private const DEFAULT_PATHS = [
        'sitemap' => '/sitemap.xml',
        'robots' => '/robots.txt',
        'ads' => '/ads.txt',
        'llms' => '/llms.txt',
        'llms_full' => '/llms-full.txt',
    ];

    /**
     * The path keys whose route exists only when the domain owns the file behind
     * it, mapped to that file and to the controller method serving it.
     *
     * robots is deliberately absent: its route is always registered, because the
     * package generates a policy for a domain that brought no file of its own.
     * These three have no generated form - an ads.txt or an llms.txt the package
     * invented would be a claim nobody made - so no file means no route.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const FILE_BACKED_PATHS = [
        'ads' => ['ads.txt', 'ads'],
        'llms' => ['llms.txt', 'llms'],
        'llms_full' => ['llms-full.txt', 'llmsFull'],
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
            }

            return;
        }

        self::$registeredDomains[$domain] = true;

        Route::domain($domain)
            ->middleware($middleware)
            ->group(function () use ($domain, $routeNamePrefix, $routes) {
                // Merged over the defaults rather than replacing them, so relocating
                // or disabling one file does not require restating the other two.
                $paths = config('multidomain-ghost.routes.paths');
                $paths = is_array($paths)
                    ? array_merge(self::DEFAULT_PATHS, $paths)
                    : self::DEFAULT_PATHS;

                if (isset($paths['robots']) && is_string($paths['robots'])) {
                    Route::name("{$routeNamePrefix}_robots")
                        ->get($paths['robots'], [GhostController::class, 'robots']);
                }

                if (isset($paths['sitemap']) && is_string($paths['sitemap'])) {
                    Route::name("{$routeNamePrefix}_sitemap")
                        ->get($paths['sitemap'], [GhostController::class, 'sitemap']);
                }

                // Decided from this domain's own file rather than from configuration:
                // configuration only ever reflects the domain active in this process,
                // so reading it here would hand one domain's answer to all the others.
                foreach (self::FILE_BACKED_PATHS as $key => [$file, $method]) {
                    if (! isset($paths[$key]) || ! is_string($paths[$key])) {
                        continue;
                    }

                    if (DomainAssets::contents($domain, $file) === null) {
                        continue;
                    }

                    Route::name("{$routeNamePrefix}_{$key}")
                        ->get($paths[$key], [GhostController::class, $method]);
                }

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

    public static function registerCatchAlls(): void
    {
        if (! (bool) config('multidomain-ghost.routes.catch_all', false)) {
            return;
        }

        $middleware = (array) config('multidomain-ghost.routes.middleware', ['web']);

        foreach (array_keys(self::$registeredDomains) as $domain) {
            $routeNamePrefix = str_replace(['.', '-'], '_', $domain);
            $routeName = "{$routeNamePrefix}_catch_all";

            if (Route::has($routeName)) {
                continue;
            }

            $sanitized = DomainName::dirKey($domain);

            Route::domain($domain)
                ->middleware($middleware)
                ->group(function () use ($routeName, $sanitized) {
                    Route::name($routeName)
                        ->get('/{path}', [GhostController::class, 'page'])
                        ->defaults('viewPath', "{$sanitized}/page")
                        ->where('path', '.*');
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
