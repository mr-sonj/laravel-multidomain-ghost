<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Console\Commands;

use Illuminate\Console\Command;

class GhostInstallCommand extends Command
{
    protected $signature = 'ghost:install
        {--force : Overwrite existing configuration files}';

    protected $aliases = ['domain:install', 'multidomain:install'];

    protected $description = 'Install and set up laravel-multidomain-ghost package automatically';

    public function handle(): int
    {
        $this->info('Installing Laravel MultiDomain Ghost package...');

        $this->publishConfig();
        $this->setupEnvironmentVariables();
        $this->patchBootstrapApp();
        $this->createDomainConfigFile();

        $this->newLine();
        $this->info('✓ Laravel MultiDomain Ghost package installed successfully!');
        $this->line('You can now add domains using: <comment>php artisan domain:add {domain.com}</comment>');

        return self::SUCCESS;
    }

    private function publishConfig(): void
    {
        $configFile = config_path('multidomain-ghost.php');

        if (file_exists($configFile) && ! $this->option('force')) {
            $this->line('<comment>! Config file already exists:</comment> config/multidomain-ghost.php');
            return;
        }

        $this->call('vendor:publish', [
            '--tag' => 'multidomain-ghost-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->line('<info>✓ Config file published:</info> config/multidomain-ghost.php');
    }

    private function setupEnvironmentVariables(): void
    {
        $envFiles = array_filter([
            base_path('.env'),
            base_path('.env.dev'),
            base_path('.env.example'),
        ], 'file_exists');

        $keysToAdd = [
            'GHOST_URL' => 'https://your-ghost-instance.com',
            'GHOST_CONTENT_KEY' => '',
            'GHOST_WEBHOOK_SECRET' => '',
        ];

        foreach ($envFiles as $envFile) {
            $content = file_get_contents($envFile);
            $appended = false;
            $relativeName = basename($envFile);

            foreach ($keysToAdd as $key => $defaultVal) {
                if (! str_contains($content, "{$key}=")) {
                    if (! $appended) {
                        $content .= "\n# Ghost CMS Settings\n";
                        $appended = true;
                    }
                    $content .= "{$key}={$defaultVal}\n";
                }
            }

            if ($appended) {
                file_put_contents($envFile, $content);
                $this->line("<info>✓ Environment variables added to {$relativeName}</info>");
            } else {
                $this->line("<comment>! Environment variables already present in {$relativeName}</comment>");
            }
        }
    }

    private function patchBootstrapApp(): void
    {
        $bootstrapFile = base_path('bootstrap/app.php');

        if (! file_exists($bootstrapFile)) {
            return;
        }

        $content = file_get_contents($bootstrapFile);

        if (str_contains($content, 'MrSonj\MultiDomainGhost\Foundation\Application')) {
            $this->line('<comment>! bootstrap/app.php already using MultiDomain Application</comment>');
            return;
        }

        $targetSearch = 'Illuminate\Foundation\Application::configure';
        if (str_contains($content, $targetSearch)) {
            $useStatement = "use MrSonj\MultiDomainGhost\Foundation\Application;\n";
            if (! str_contains($content, 'use MrSonj\MultiDomainGhost\Foundation\Application;')) {
                $content = preg_replace('/(<\?php\n)/', "$1\n{$useStatement}", $content, 1);
            }
            $content = str_replace($targetSearch, 'Application::configure', $content);

            file_put_contents($bootstrapFile, $content);
            $this->line('<info>✓ Updated bootstrap/app.php to use MultiDomain Application</info>');
        }
    }

    private function createDomainConfigFile(): void
    {
        $configDomainFile = config_path('domain.php');

        if (file_exists($configDomainFile)) {
            $this->line('<comment>! config/domain.php already exists</comment>');
            return;
        }

        $stub = <<<PHP
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Multi-Domain Registry
    |--------------------------------------------------------------------------
    | Registered domain mappings for the application.
    */
    'domains' => [
        // 'example.com' => 'example.com',
    ],
];
PHP;

        file_put_contents($configDomainFile, $stub);
        $this->line('<info>✓ Created config/domain.php registry</info>');
    }
}
