<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Console\Commands;

use Illuminate\Console\Command;
use MrSonj\MultiDomainGhost\Services\DomainResolver;
use MrSonj\MultiDomainGhost\Support\DomainName;

class GhostDomainAddCommand extends Command
{
    protected $signature = 'domain:add
        {domain : Raw domain name, e.g. example.com}
        {--force : Recreate any missing domain storage directories}';

    protected $aliases = ['ghost:domain-add'];

    protected $description = 'Register a new domain, scaffold storage, config overrides, routes, views, CSS and Vite entries automatically';

    public function handle(): int
    {
        $domain = DomainResolver::normalizeDomain((string) $this->argument('domain'));
        if (! DomainName::isRegistrable($domain)) {
            $this->error("Invalid domain name [{$domain}].");

            return self::FAILURE;
        }

        $sanitized = DomainResolver::dirKeyFor($domain);
        $tagSlug = DomainResolver::domainTagSlug($domain);

        $this->info("Registering domain: {$domain} (key: {$sanitized}, tag: #{$tagSlug})");

        // 1. Create storage directories
        $storageBase = base_path("storage/{$sanitized}");
        $subDirs = [
            'app/public',
            'framework/cache/data',
            'framework/sessions',
            'framework/testing',
            'framework/views',
            'logs',
        ];

        foreach ($subDirs as $subDir) {
            $path = "{$storageBase}/{$subDir}";
            if (! is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
        $this->line("<info>✓ Storage directory ready:</info> storage/{$sanitized}");

        // 2. Create config override file config/domains/{sanitized}.php
        $configDir = config_path('domains');
        if (! is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $configFile = "{$configDir}/{$sanitized}.php";
        if (! file_exists($configFile)) {
            $parts = explode('.', $domain);
            $studlyName = ucfirst($parts[0]);
            $stub = <<<PHP
<?php

/**
 * Domain-specific config overrides for {$domain}
 */

return [
    'app.name' => '{$studlyName}',
    'app.url' => 'https://{$domain}',
    'cache.prefix' => '{$sanitized}_cache',
];
PHP;
            file_put_contents($configFile, $stub);
            $this->line("<info>✓ Config override created:</info> config/domains/{$sanitized}.php");
        } else {
            $this->line("<comment>! Config override already exists:</comment> config/domains/{$sanitized}.php");
        }

        // 3. Create view folder & scaffold views
        $viewDir = resource_path("views/{$sanitized}");
        if (! is_dir($viewDir)) {
            mkdir($viewDir, 0755, true);
            $this->line("<info>✓ View folder created:</info> resources/views/{$sanitized}");
        }
        $this->scaffoldViews($sanitized, $domain);

        // 4. Create CSS file
        $cssFile = resource_path("css/{$sanitized}.css");
        if (! file_exists($cssFile)) {
            @mkdir(dirname($cssFile), 0755, true);
            file_put_contents($cssFile, "/* CSS for {$domain} */\n@import \"tailwindcss\";\n");
            $this->line("<info>✓ CSS file created:</info> resources/css/{$sanitized}.css");
        }

        // 5. Update config/domain.php
        $configDomainFile = config_path('domain.php');
        $domainConfig = file_exists($configDomainFile) ? require $configDomainFile : [];
        if (! is_array($domainConfig)) {
            $this->error('config/domain.php must return an array.');

            return self::FAILURE;
        }

        $domainConfig['domains'] = is_array($domainConfig['domains'] ?? null)
            ? $domainConfig['domains']
            : [];
        $domainConfig['domains'][$domain] = $domain;
        ksort($domainConfig['domains']);

        $phpContent = "<?php\n\nreturn ".var_export($domainConfig, true).";\n";
        file_put_contents($configDomainFile, $phpContent);
        config()->set('domain', $domainConfig);
        $this->line('<info>✓ Domain registered in config/domain.php</info>');

        // 6. Auto-inject CSS entry into vite.config.js
        $this->injectViteConfig($sanitized);

        // 7. Auto-inject Route group into routes/web.php
        $this->injectWebRoutes($domain, $sanitized);

        // 8. Auto-update local Herd config if present
        $this->updateHerdConfig($domain);

        if (file_exists(base_path(".env.{$domain}"))) {
            $this->warn("A legacy .env.{$domain} exists; this package did not create or modify it.");
        }

        $this->newLine();
        $this->info("✓ Domain {$domain} registered and scaffolded successfully!");

        return self::SUCCESS;
    }

    private function scaffoldViews(string $sanitized, string $domain): void
    {
        $mainView = resource_path("views/{$sanitized}/main.blade.php");
        if (! file_exists($mainView)) {
            $stubMain = <<<BLADE
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ \$seo['title'] ?? config('app.name') }}</title>
    @vite(['resources/css/' . App\Helper::dir() . '.css'])
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen font-sans antialiased">
    @yield('content')
</body>
</html>
BLADE;
            file_put_contents($mainView, $stubMain);
            $this->line("<info>✓ Layout created:</info> resources/views/{$sanitized}/main.blade.php");
        }

        $homeView = resource_path("views/{$sanitized}/home.blade.php");
        if (! file_exists($homeView)) {
            $stubHome = <<<BLADE
@extends(App\Helper::dir().'/main')

@section('content')
    <main class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold mb-4">{{ \$content['title'] ?? 'Welcome' }}</h1>
        <article class="prose max-w-none">
            {!! \$content['html'] ?? '<p>Welcome to ' . config('app.name') . '</p>' !!}
        </article>
    </main>
@endsection
BLADE;
            file_put_contents($homeView, $stubHome);
            $this->line("<info>✓ Home view created:</info> resources/views/{$sanitized}/home.blade.php");
        }
    }

    private function injectViteConfig(string $sanitized): void
    {
        $viteFile = base_path('vite.config.js');
        if (! file_exists($viteFile)) {
            return;
        }

        $cssEntry = "resources/css/{$sanitized}.css";
        $content = file_get_contents($viteFile);

        if (str_contains($content, $cssEntry)) {
            $this->line("<comment>! Vite entry already exists:</comment> {$cssEntry}");
            return;
        }

        // Search for input: [ ... ] array
        if (preg_match('/(input\s*:\s*\[)([^\]]+)(\])/', $content, $matches)) {
            $existingInputs = $matches[2];
            $trimmedInputs = rtrim($existingInputs);
            if (! str_ends_with($trimmedInputs, ',')) {
                $existingInputs .= ',';
            }
            $replacement = $matches[1] . $existingInputs . "\n                '{$cssEntry}'" . $matches[3];
            $content = str_replace($matches[0], $replacement, $content);

            file_put_contents($viteFile, $content);
            $this->line("<info>✓ Vite config updated with entry:</info> {$cssEntry}");
        }
    }

    private function injectWebRoutes(string $domain, string $sanitized): void
    {
        $routesFile = base_path('routes/web.php');
        if (! file_exists($routesFile)) {
            return;
        }

        $content = file_get_contents($routesFile);

        if (str_contains($content, "Route::domain('{$domain}')") || str_contains($content, 'Route::domain("'.$domain.'")')) {
            $this->line("<comment>! Route group already exists in routes/web.php for:</comment> {$domain}");
            return;
        }

        $routeNamePrefix = str_replace(['.', '-'], '_', $domain);

        $routeStub = <<<PHP


Route::domain('{$domain}')->group(function () {
    Route::get('/robots.txt', [\MrSonj\MultiDomainGhost\Http\Controllers\GhostController::class, 'robots']);
    Route::get('/sitemap.xml', [\MrSonj\MultiDomainGhost\Http\Controllers\GhostController::class, 'sitemap']);
    Route::get('/feed', [\MrSonj\MultiDomainGhost\Http\Controllers\GhostController::class, 'feed']);
    Route::get('/ads.txt', [\MrSonj\MultiDomainGhost\Http\Controllers\GhostController::class, 'ads']);

    Route::get('/', [\MrSonj\MultiDomainGhost\Http\Controllers\GhostController::class, 'page'])
        ->defaults('viewPath', '{$sanitized}/home')
        ->name('{$routeNamePrefix}_home');
});
PHP;

        file_put_contents($routesFile, $content . $routeStub);
        $this->line("<info>✓ Route group injected into routes/web.php for:</info> {$domain}");
    }

    private function updateHerdConfig(string $domain): void
    {
        $herdFile = base_path('_setup/multi_domain_local_herd.conf');
        if (! file_exists($herdFile)) {
            return;
        }

        $content = file_get_contents($herdFile);

        if (str_contains($content, $domain)) {
            $this->line("<comment>! Local Herd config already contains domain:</comment> {$domain}");
            return;
        }

        // Add domain to server_name lines
        $content = preg_replace_callback('/(server_name\s+)([^;]+)(;)/', function ($matches) use ($domain) {
            return $matches[1] . trim($matches[2]) . " {$domain} www.{$domain}" . $matches[3];
        }, $content);

        file_put_contents($herdFile, $content);
        $this->line("<info>✓ Updated _setup/multi_domain_local_herd.conf with:</info> {$domain}");
    }
}
