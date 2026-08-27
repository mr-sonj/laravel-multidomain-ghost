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
        {--force : Overwrite this domain\'s generated views and CSS, keeping a .bak of whatever they held}
        {--force-routes : Also overwrite routes/domains/{key}.php, which --force deliberately leaves alone}';

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
        $routeStub .= "\n";
        $this->writeScaffold($routeFile, $routeStub, $this->mayOverwriteRoutes($routeFile, $routeStub), 'Route file');

        // 4. Create view folder & scaffold views
        $viewDir = resource_path("views/{$sanitized}");
        if (! is_dir($viewDir)) {
            mkdir($viewDir, 0755, true);
            $this->line("<info>✓ View folder created:</info> resources/views/{$sanitized}");
        }
        $this->scaffoldViews($sanitized, $domain);

        // The same folder holds this domain's robots.txt, ads.txt and llms.txt.
        // No stub files: a stub robots.txt would switch this domain off generated
        // output - Sitemap: line included - without anyone noticing, and an empty
        // ads.txt claims the domain authorises no sellers.
        $this->line('  <comment>Drop ads.txt, llms.txt or llms-full.txt in that folder and each gets its route; a robots.txt there replaces the generated one, Sitemap: line included.</comment>');

        // 5. Create CSS file
        $cssFile = resource_path("css/{$sanitized}.css");
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
        $this->writeScaffold($cssFile, $css."\n", (bool) $this->option('force'), 'CSS file');

        // 6. Auto-inject CSS entry into vite.config.js
        $this->injectViteConfig($sanitized);

        // 7. Auto-update local Herd config if present
        $this->updateHerdConfig($domain);

        if (file_exists(base_path(".env.{$domain}"))) {
            $this->warn("A legacy .env.{$domain} exists; this package did not create or modify it.");
        }

        DomainRegistry::flush();

        $this->newLine();
        $this->info("✓ Domain {$domain} registered and scaffolded successfully!");

        return self::SUCCESS;
    }

    /**
     * Write one scaffolded file, and never silently destroy what it held.
     *
     * The three rules this enforces, in order:
     *
     * A file already holding exactly these bytes is left untouched. Re-running
     * domain:add after a package upgrade is the supported way to pick up new
     * scaffolding, so its output has to be the short list of what actually
     * changed, not the same nine lines every time.
     *
     * A file that differs and may not be overwritten is reported, not replaced.
     *
     * A file that differs and *is* being overwritten is copied to a timestamped
     * .bak first. Anything reachable here is a file somebody edited, and a
     * generator that eats an afternoon's work because a flag was one word wider
     * than the user meant is not a generator anybody can run twice.
     */
    private function writeScaffold(string $path, string $contents, bool $overwrite, string $label): void
    {
        $relative = $this->relativePath($path);
        $exists = is_file($path);
        $current = $exists ? (string) file_get_contents($path) : null;

        if ($current === $contents) {
            return;
        }

        if ($exists && ! $overwrite) {
            $this->line("<comment>! {$label} kept as it is:</comment> {$relative}");

            return;
        }

        if ($exists && ! $this->backup($path)) {
            return;
        }

        if (file_put_contents($path, $contents) === false) {
            $this->warn("Could not write {$relative}");

            return;
        }

        $this->line(sprintf(
            '<info>✓ %s %s:</info> %s',
            $label,
            $exists ? 'replaced' : 'created',
            $relative,
        ));
    }

    /**
     * Copy a file aside before it is overwritten, reporting where it went.
     *
     * Timestamped rather than a single .bak so that two runs in a row cannot
     * leave the backup holding generated output instead of the original.
     * Returns false when the copy failed, which aborts the overwrite: losing the
     * edits is the outcome this whole path exists to prevent.
     */
    private function backup(string $path): bool
    {
        $backup = $path.'.'.date('Ymd-His').'.bak';

        if (! @copy($path, $backup)) {
            $this->warn("Could not back up {$this->relativePath($path)}; leaving it untouched.");

            return false;
        }

        $this->line("  <comment>Previous contents saved to</comment> {$this->relativePath($backup)}");

        return true;
    }

    /**
     * Whether this run may replace an existing routes/domains/{key}.php.
     *
     * Behind its own flag rather than --force because the route file is the one
     * piece of this scaffold everybody is expected to edit - it is where a
     * domain's own routes live. --force exists to refresh the view and CSS
     * stubs, and sweeping a domain's routing away as a side effect of asking for
     * fresh CSS is a trade nobody would agree to if asked.
     */
    private function mayOverwriteRoutes(string $path, string $stub): bool
    {
        if (! $this->option('force-routes')) {
            return false;
        }

        if (! is_file($path) || (string) file_get_contents($path) === $stub) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->warn("Replacing an edited {$this->relativePath($path)} because --force-routes was passed.");

            return true;
        }

        return $this->confirm(
            "{$this->relativePath($path)} has been edited since it was scaffolded. Replace it?",
            false,
        );
    }

    private function relativePath(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);
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
            $this->writeScaffold(
                resource_path("views/{$sanitized}/{$filename}"),
                $stub."\n",
                (bool) $this->option('force'),
                'View',
            );
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
