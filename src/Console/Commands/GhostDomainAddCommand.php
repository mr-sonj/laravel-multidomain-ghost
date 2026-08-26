<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Console\Commands;

use Illuminate\Console\Command;
use MrSonj\MultiDomainGhost\Support\Domain;
use MrSonj\MultiDomainGhost\Support\DomainCacheFiles;
use MrSonj\MultiDomainGhost\Support\DomainName;
use MrSonj\MultiDomainGhost\Support\DomainRegistry;

class GhostDomainAddCommand extends Command
{
    protected $signature = 'domain:add
        {domain : Raw domain name, e.g. example.com}
        {--force : Overwrite generated domain views and CSS}';

    protected $aliases = ['ghost:domain-add'];

    protected $description = 'Register a new domain, scaffold storage, config overrides, views, CSS and Vite entries automatically';

    public function handle(): int
    {
        $name = Domain::make((string) $this->argument('domain'));
        $domain = $name->host();
        if (! DomainName::isRegistrable($domain)) {
            $this->error("Invalid domain name [{$domain}].");

            return self::FAILURE;
        }

        $failedCacheFiles = DomainCacheFiles::clear($domain);
        if ($failedCacheFiles !== []) {
            $this->error('Could not clear stale domain caches: '.implode(', ', $failedCacheFiles));

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

        // 3. Create route file routes/domains/{sanitized}.php
        $routeDir = base_path('routes/domains');
        if (! is_dir($routeDir)) {
            mkdir($routeDir, 0755, true);
        }

        $routeFile = "{$routeDir}/{$sanitized}.php";
        if (! file_exists($routeFile) || $this->option('force')) {
            $routeNamePrefix = str_replace(['.', '-'], '_', $domain);
            $routeStub = <<<PHP
<?php

use Illuminate\Support\Facades\Route;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostController;

Route::get('/', [GhostController::class, 'page'])
    ->name('{$routeNamePrefix}_home')
    ->defaults('viewPath', '{$sanitized}/home');

Route::get('/blog', [GhostController::class, 'blog'])
    ->name('{$routeNamePrefix}_blog')
    ->defaults('viewPath', '{$sanitized}/blog');

Route::get('/blog/{slug}', [GhostController::class, 'page'])
    ->name('{$routeNamePrefix}_post')
    ->defaults('viewPath', '{$sanitized}/post')
    ->where('slug', '[A-Za-z0-9\-_]+');

Route::get('/feed', [GhostController::class, 'feed'])
    ->name('{$routeNamePrefix}_feed');
PHP;
            file_put_contents($routeFile, $routeStub."\n");
            $this->line("<info>✓ Route file ready:</info> routes/domains/{$sanitized}.php");
        } else {
            $this->line("<comment>! Route file already exists:</comment> routes/domains/{$sanitized}.php");
        }

        // 4. Create view folder & scaffold views
        $viewDir = resource_path("views/{$sanitized}");
        if (! is_dir($viewDir)) {
            mkdir($viewDir, 0755, true);
            $this->line("<info>✓ View folder created:</info> resources/views/{$sanitized}");
        }
        $this->scaffoldViews($sanitized, $domain);

        // 5. Create the per-domain assets folder for robots.txt and ads.txt.
        // No stub files: a stub robots.txt would switch this domain off generated
        // output - Sitemap: line included - without anyone noticing, and an empty
        // ads.txt claims the domain authorises no sellers.
        $assetsDir = resource_path("domains/{$sanitized}");
        if (! is_dir($assetsDir)) {
            mkdir($assetsDir, 0755, true);
            $this->line("<info>✓ Assets folder created:</info> resources/domains/{$sanitized}");
        }
        $this->line('  <comment>Put ads.txt there to publish it; a robots.txt there replaces the generated one, Sitemap: line included.</comment>');

        // 6. Create CSS file
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

        // 7. Auto-inject CSS entry into vite.config.js
        $this->injectViteConfig($sanitized);

        // 8. Auto-update local Herd config if present
        $this->updateHerdConfig($domain);

        if (file_exists(base_path(".env.{$domain}"))) {
            $this->warn("A legacy .env.{$domain} exists; this package did not create or modify it.");
        }

        DomainRegistry::flush();

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
