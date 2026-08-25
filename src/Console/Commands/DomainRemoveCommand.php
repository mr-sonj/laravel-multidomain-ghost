<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use MrSonj\MultiDomainGhost\Support\Domain;
use MrSonj\MultiDomainGhost\Support\DomainName;

class DomainRemoveCommand extends Command
{
    protected $signature = 'domain:remove
        {domain : Raw domain name, e.g. example.com}
        {--force : Also delete the domain storage directory}';

    protected $description = 'Unregister a domain without deleting its config override';

    public function handle(Filesystem $files): int
    {
        $name = Domain::make((string) $this->argument('domain'));
        $domain = $name->host();
        if (! DomainName::isRegistrable($domain)) {
            $this->error("Invalid domain name [{$domain}].");

            return self::FAILURE;
        }

        $configFile = config_path('domain.php');
        $domainConfig = is_file($configFile) ? require $configFile : [];

        if (! is_array($domainConfig)) {
            $this->error('config/domain.php must return an array.');

            return self::FAILURE;
        }

        unset($domainConfig['domains'][$domain]);

        $phpContent = "<?php\n\nreturn ".var_export($domainConfig, true).";\n";
        $files->put($configFile, $phpContent);
        config()->set('domain', $domainConfig);

        if ($this->option('force')) {
            $storagePath = base_path('storage/'.$name->key());
            if ($files->isDirectory($storagePath)) {
                $files->deleteDirectory($storagePath);
                $this->warn("Deleted {$storagePath}");
            }
        }

        $this->info("Domain {$domain} unregistered. Its config/domains override was preserved.");

        return self::SUCCESS;
    }
}
