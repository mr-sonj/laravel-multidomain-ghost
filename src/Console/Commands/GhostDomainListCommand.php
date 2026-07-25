<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Console\Commands;

use Illuminate\Console\Command;
use MrSonj\MultiDomainGhost\Services\DomainResolver;

class GhostDomainListCommand extends Command
{
    protected $signature = 'domain:list';

    protected $aliases = ['ghost:domain-list'];

    protected $description = 'List all configured multi-domain sites and their storage/config status';

    public function handle(): int
    {
        $domains = config('domain.domains', []);

        if (empty($domains) || ! is_array($domains)) {
            $this->warn('No domains registered in config/domain.php');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($domains as $domain => $raw) {
            $sanitized = DomainResolver::dirKeyFor((string) $domain);
            $storageExists = is_dir(base_path("storage/{$sanitized}")) ? 'Yes' : 'No';
            $configExists = file_exists(config_path("domains/{$sanitized}.php")) ? 'Yes' : 'No';
            $tagSlug = DomainResolver::domainTagSlug((string) $domain);

            $rows[] = [
                $domain,
                $sanitized,
                $tagSlug,
                $storageExists,
                $configExists,
            ];
        }

        $this->table(['Domain', 'Sanitized Key', 'Ghost Tag', 'Storage Dir', 'Config Override'], $rows);

        return self::SUCCESS;
    }
}
