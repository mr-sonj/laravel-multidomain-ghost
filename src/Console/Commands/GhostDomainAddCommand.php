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

    protected $description = 'Register a new domain, scaffold storage, config overrides, views and CSS without creating .env files';

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

        // 3. Create view folder
        $viewDir = resource_path("views/{$sanitized}");
        if (! is_dir($viewDir)) {
            mkdir($viewDir, 0755, true);
            $this->line("<info>✓ View folder created:</info> resources/views/{$sanitized}");
        }

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

        if (file_exists(base_path(".env.{$domain}"))) {
            $this->warn("A legacy .env.{$domain} exists; this package did not create or modify it.");
        }

        $this->info("Domain {$domain} registered successfully!");

        return self::SUCCESS;
    }
}
