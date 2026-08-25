<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Console\Commands;

use Illuminate\Console\Command;
use MrSonj\MultiDomainGhost\Support\Domain;
use MrSonj\MultiDomainGhost\Support\DomainName;

class GhostDomainAddCommand extends Command
{
    protected $signature = 'domain:add
        {domain : Raw domain name, e.g. example.com}
        {--force : Overwrite generated domain views and CSS}';

    protected $aliases = ['ghost:domain-add'];

    protected $description = 'Register a new domain, scaffold storage, config overrides, routes, views, CSS and Vite entries automatically';

    public function handle(): int
    {
        $name = Domain::make((string) $this->argument('domain'));
        $domain = $name->host();
        if (! DomainName::isRegistrable($domain)) {
            $this->error("Invalid domain name [{$domain}].");

            return self::FAILURE;
        }

        $sanitized = $name->key();
        $tagSlug = $name->tag();

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
        if (! file_exists($cssFile) || $this->option('force')) {
            @mkdir(dirname($cssFile), 0755, true);
            $css = <<<CSS
            /* Base styles for {$domain}. Replace or extend these styles as needed. */
            :root {
                color-scheme: light;
                font-family: ui-sans-serif, system-ui, sans-serif;
                line-height: 1.6;
            }

            body {
                margin: 0;
                color: #111827;
                background: #f9fafb;
            }

            .container {
                width: min(72rem, calc(100% - 2rem));
                margin-inline: auto;
                padding-block: 2rem;
            }

            .post-list {
                display: grid;
                gap: 1.5rem;
                padding: 0;
                list-style: none;
            }

            a {
                color: #2563eb;
            }
            CSS;
            file_put_contents($cssFile, $css."\n");
            $this->line("<info>✓ CSS file ready:</info> resources/css/{$sanitized}.css");
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
        $stubMain = <<<BLADE
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ \$seo['title'] ?? config('app.name') }}</title>
    @if (! empty(\$seo['description']))
        <meta name="description" content="{{ \$seo['description'] }}">
    @endif
    @if (! empty(\$seo['canonical_url']))
        <link rel="canonical" href="{{ \$seo['canonical_url'] }}">
    @endif
    @vite('resources/css/{$sanitized}.css')
</head>
<body>
    @yield('content')
</body>
</html>
BLADE;

        $stubHome = <<<BLADE
@extends('{$sanitized}.main')

@section('content')
    <main class="container">
        <h1>{{ \$content['title'] ?? 'Welcome' }}</h1>
        <article>
            {!! \$content['html'] ?? '<p>Welcome to ' . config('app.name') . '</p>' !!}
        </article>
    </main>
@endsection
BLADE;

        $stubPage = <<<BLADE
@extends('{$sanitized}.main')

@section('content')
    <main class="container">
        <article>
            <h1>{{ \$content['title'] ?? '' }}</h1>
            {!! \$content['html'] ?? '' !!}
        </article>
    </main>
@endsection
BLADE;

        $stubBlog = <<<BLADE
@extends('{$sanitized}.main')

@section('content')
    <main class="container">
        <h1>{{ \$content['title'] ?? 'Blog' }}</h1>
        <ul class="post-list">
            @forelse ((\$dataBlog['posts'] ?? []) as \$post)
                <li>
                    <article>
                        <h2>
                            <a href="{{ \$post['canonical_url'] ?? '#' }}">
                                {{ \$post['title'] ?? '' }}
                            </a>
                        </h2>
                        @if (! empty(\$post['excerpt']))
                            <p>{{ \$post['excerpt'] }}</p>
                        @endif
                    </article>
                </li>
            @empty
                <li>No posts found.</li>
            @endforelse
        </ul>
    </main>
@endsection
BLADE;

        $views = [
            'main.blade.php' => $stubMain,
            'home.blade.php' => $stubHome,
            'page.blade.php' => $stubPage,
            'post.blade.php' => $stubPage,
            'contact.blade.php' => $stubPage,
            'blog.blade.php' => $stubBlog,
        ];

        foreach ($views as $filename => $stub) {
            $view = resource_path("views/{$sanitized}/{$filename}");
            if (file_exists($view) && ! $this->option('force')) {
                $this->line("<comment>! View already exists:</comment> resources/views/{$sanitized}/{$filename}");

                continue;
            }

            if (file_put_contents($view, $stub."\n") === false) {
                $this->warn("Could not write resources/views/{$sanitized}/{$filename}");

                continue;
            }

            $this->line("<info>✓ View ready:</info> resources/views/{$sanitized}/{$filename}");
        }
    }

    private function injectViteConfig(string $sanitized): void
    {
        $viteFile = base_path('vite.config.js');
        if (! file_exists($viteFile)) {
            $this->warn("vite.config.js was not found. Add 'resources/css/{$sanitized}.css' to your build manually.");

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
            $replacement = $matches[1].$existingInputs."\n                '{$cssEntry}'".$matches[3];
            $content = str_replace($matches[0], $replacement, $content);

            file_put_contents($viteFile, $content);
            $this->line("<info>✓ Vite config updated with entry:</info> {$cssEntry}");

            return;
        }

        $this->warn("Could not identify Vite's input array. Add '{$cssEntry}' manually.");
    }

    private function injectWebRoutes(string $domain, string $sanitized): void
    {
        $routesFile = base_path('routes/web.php');
        if (! file_exists($routesFile)) {
            $this->warn('routes/web.php was not found. Domain routes were not generated.');

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
    Route::get('/robots.txt', [\MrSonj\MultiDomainGhost\Http\Controllers\GhostController::class, 'robots'])
        ->name('{$routeNamePrefix}_robots');
    Route::get('/sitemap.xml', [\MrSonj\MultiDomainGhost\Http\Controllers\GhostController::class, 'sitemap'])
        ->name('{$routeNamePrefix}_sitemap');
    Route::get('/feed', [\MrSonj\MultiDomainGhost\Http\Controllers\GhostController::class, 'feed'])
        ->name('{$routeNamePrefix}_feed');
    Route::get('/ads.txt', [\MrSonj\MultiDomainGhost\Http\Controllers\GhostController::class, 'ads']);

    Route::get('/', [\MrSonj\MultiDomainGhost\Http\Controllers\GhostController::class, 'page'])
        ->defaults('viewPath', '{$sanitized}/home')
        ->name('{$routeNamePrefix}_home');

    Route::get('/blog', [\MrSonj\MultiDomainGhost\Http\Controllers\GhostController::class, 'blog'])
        ->defaults('viewPath', '{$sanitized}/blog')
        ->name('{$routeNamePrefix}_blog');
    Route::get('/blog/{slug}', [\MrSonj\MultiDomainGhost\Http\Controllers\GhostController::class, 'page'])
        ->defaults('viewPath', '{$sanitized}/post')
        ->name('{$routeNamePrefix}_post');
});

// Without this, requests to the www host match no route at all and 404.
Route::domain('www.{$domain}')->group(function () {
    Route::get('/{path?}', function (?string \$path = null) {
        return redirect()->away('https://{$domain}/'.ltrim((string) \$path, '/'), 301);
    })->where('path', '.*')->name('{$routeNamePrefix}_www_redirect');
});
PHP;

        file_put_contents($routesFile, $content.$routeStub);
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
            return $matches[1].trim($matches[2])." {$domain} www.{$domain}".$matches[3];
        }, $content);

        file_put_contents($herdFile, $content);
        $this->line("<info>✓ Updated _setup/multi_domain_local_herd.conf with:</info> {$domain}");
    }
}
