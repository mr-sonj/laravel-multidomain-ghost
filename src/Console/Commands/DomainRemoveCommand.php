<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use MrSonj\MultiDomainGhost\Support\Domain;
use MrSonj\MultiDomainGhost\Support\DomainCacheFiles;
use MrSonj\MultiDomainGhost\Support\DomainName;
use MrSonj\MultiDomainGhost\Support\DomainRegistry;

class DomainRemoveCommand extends Command
{
    protected $signature = 'domain:remove
        {domain : Raw domain name, e.g. example.com}
        {--force : Also delete the domain storage directory}';

    protected $description = 'Remove a domain by deleting its config override';

    public function handle(Filesystem $files): int
    {
        $name = Domain::make((string) $this->argument('domain'));
        $domain = $name->host();
        if (! DomainName::isRegistrable($domain)) {
            $this->error("Invalid domain name [{$domain}].");

            return self::FAILURE;
        }

        $configFile = config_path("domains/{$name->key()}.php");

        if (! $files->exists($configFile)) {
            $this->error("Domain [{$domain}] is not registered (config/domains/{$name->key()}.php not found).");

            return self::FAILURE;
        }

        $failedCacheFiles = DomainCacheFiles::clear($domain);
        if ($failedCacheFiles !== []) {
            $this->error('Could not clear stale domain caches: '.implode(', ', $failedCacheFiles));

            return self::FAILURE;
        }

        if (! $files->delete($configFile)) {
            $this->error("Could not delete config/domains/{$name->key()}.php.");

            return self::FAILURE;
        }

        if ($this->option('force')) {
            $storagePath = base_path('storage/'.$name->key());
            if ($files->isDirectory($storagePath)) {
                $files->deleteDirectory($storagePath);
                $this->warn("Deleted {$storagePath}");
            }
        }

        DomainRegistry::flush();

        $this->info("Domain {$domain} removed.");

        return self::SUCCESS;
    }
}
