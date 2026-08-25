<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost;

use Closure;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MrSonj\MultiDomainGhost\Client\GhostClient;
use MrSonj\MultiDomainGhost\Console\Commands\DomainCurrentCommand;
use MrSonj\MultiDomainGhost\Console\Commands\DomainOptimizeCommand;
use MrSonj\MultiDomainGhost\Console\Commands\DomainRemoveCommand;
use MrSonj\MultiDomainGhost\Console\Commands\GhostDomainAddCommand;
use MrSonj\MultiDomainGhost\Console\Commands\GhostDomainListCommand;
use MrSonj\MultiDomainGhost\Console\Commands\GhostInstallCommand;
use MrSonj\MultiDomainGhost\Contracts\ContentTransformerInterface;
use MrSonj\MultiDomainGhost\Contracts\DomainEnricherInterface;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostWebhookController;
use MrSonj\MultiDomainGhost\Routing\GhostRouteRegistrar;
use MrSonj\MultiDomainGhost\Services\DomainResolver;
use MrSonj\MultiDomainGhost\Services\GhostCacheManager;
use MrSonj\MultiDomainGhost\Services\GhostContentService;
use MrSonj\MultiDomainGhost\Support\DomainEnricherLocator;
use MrSonj\MultiDomainGhost\Support\GhostCache;
use MrSonj\MultiDomainGhost\Support\NullContentTransformer;
use MrSonj\MultiDomainGhost\Support\NullEnricher;

class MultiDomainGhostServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/multidomain-ghost.php', 'multidomain-ghost');

        $this->app->scoped(DomainResolver::class);

        $this->app->scoped(GhostClient::class, function ($app) {
            $resolver = $app->make(DomainResolver::class);
            $domain = $resolver->resolve();
            $apiMode = strtolower((string) config('multidomain-ghost.api_mode', 'auto'));
            $hasAdminCredentials = filled(config('multidomain-ghost.admin_url'))
                && filled(config('multidomain-ghost.admin_key'));
            $usesAdminApi = $apiMode === 'admin'
                || ($apiMode === 'auto' && $app->environment('local') && $hasAdminCredentials);

            return new GhostClient(
                $domain,
                $usesAdminApi,
                $app->make(ContentTransformerInterface::class),
            );
        });

        $this->app->scoped(GhostContentService::class);
        $this->app->scoped(GhostCacheManager::class);

        $this->app->bindIf(DomainEnricherInterface::class, function ($app) {
            $domain = $app->make(DomainResolver::class)->resolve();
            $enricher = DomainEnricherLocator::resolveClass($domain);

            return $enricher !== null ? $app->make($enricher) : new NullEnricher;
        });

        $this->app->bindIf(ContentTransformerInterface::class, function ($app) {
            $transformerConfig = config('multidomain-ghost.transformer');
            if ($transformerConfig && class_exists((string) $transformerConfig)) {
                return $app->make($transformerConfig);
            }

            $conventionClass = 'App\\Services\\GhostContentTransformer';
            if (class_exists($conventionClass)) {
                return $app->make($conventionClass);
            }

            return new NullContentTransformer;
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                DomainCurrentCommand::class,
                DomainOptimizeCommand::class,
                DomainRemoveCommand::class,
                GhostDomainAddCommand::class,
                GhostDomainListCommand::class,
                GhostInstallCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        // Declared here rather than lazily on first use, so that `cache:clear
        // multidomain-ghost` and anything else reading config/cache.php can see
        // the store. Booting, not registering: the application's own providers
        // get to settle `multidomain-ghost.cache.store` first.
        GhostCache::ensureStoreRegistered();

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'multidomain-ghost');
        $this->registerRouteMacro();
        $this->registerDomainRoutes();
        $this->registerWebhookRoute();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/multidomain-ghost.php' => config_path('multidomain-ghost.php'),
            ], 'multidomain-ghost-config');
        }
    }

    private function registerRouteMacro(): void
    {
        Route::macro('ghostDomain', function (string $domain, ?Closure $routes = null) {
            GhostRouteRegistrar::registerDomain($domain, $routes);
        });
    }

    private function registerDomainRoutes(): void
    {
        if ((bool) config('multidomain-ghost.routes.auto_register', true)) {
            Route::middleware('web')->group(static function (): void {
                GhostRouteRegistrar::registerAll();
            });
        }
    }

    private function registerWebhookRoute(): void
    {
        $webhook = (array) config('multidomain-ghost.routes.webhook', []);

        if ($webhook['enabled'] ?? true) {
            Route::middleware((array) ($webhook['middleware'] ?? []))
                ->post((string) ($webhook['uri'] ?? 'webhook/ghost/post'), GhostWebhookController::class)
                ->name('multidomain-ghost.webhook');
        }
    }
}
