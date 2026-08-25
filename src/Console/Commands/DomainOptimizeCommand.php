<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Console\Commands;

use Illuminate\Console\Command;
use MrSonj\MultiDomainGhost\Support\DomainName;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Builds the framework caches once per registered domain.
 *
 * Cached config, routes and events are stored per domain, so a bare
 * `php artisan config:cache` writes a file no domain request ever reads - the
 * cache silently does nothing. This runs the build for each domain in turn.
 */
class DomainOptimizeCommand extends Command
{
    protected $signature = 'domain:optimize
        {--clear : Clear the cached files instead of building them}
        {--only= : Limit the run to a single domain}
        {--pretend : Print the commands without running them}';

    protected $description = 'Build (or clear) the config, route and event caches for every registered domain';

    public function handle(): int
    {
        $domains = $this->registeredDomains();

        if ($only = $this->option('only')) {
            $only = DomainName::normalize((string) $only);
            $domains = array_values(array_filter($domains, static fn (string $d): bool => $d === $only));

            if ($domains === []) {
                $this->error("Domain [{$only}] is not registered.");

                return self::FAILURE;
            }
        }

        if ($domains === []) {
            $this->error('No domains registered. Add one with: php artisan domain:add example.com');

            return self::FAILURE;
        }

        $steps = $this->option('clear')
            ? ['optimize:clear']
            : ['config:cache', 'route:cache', 'event:cache'];

        $failed = false;

        foreach ($domains as $domain) {
            $this->line("<info>{$domain}</info>");

            foreach ($steps as $step) {
                if (! $this->runStep($step, $domain)) {
                    $failed = true;
                }
            }
        }

        if ($failed) {
            $this->newLine();
            $this->error('One or more domains failed.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function runStep(string $step, string $domain): bool
    {
        $this->line("  php artisan {$step} --domain={$domain}");

        if ($this->option('pretend')) {
            return true;
        }

        $process = new Process(
            [$this->phpBinary(), 'artisan', $step, "--domain={$domain}"],
            base_path(),
            null,
            null,
            null,
        );

        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $this->error("  {$step} failed for {$domain}.");

            return false;
        }

        return true;
    }

    /**
     * Registered domains, preferring the package allowlist and falling back to
     * the config/domain.php registry that domain:add maintains.
     */
    private function registeredDomains(): array
    {
        $packageDomains = array_values(array_filter((array) config('multidomain-ghost.domains', [])));

        if ($packageDomains !== []) {
            return array_values(array_unique(array_map(
                static fn ($domain): string => DomainName::normalize((string) $domain),
                $packageDomains,
            )));
        }

        return array_values(array_unique(array_map(
            static fn ($domain): string => DomainName::normalize((string) $domain),
            array_keys((array) config('domain.domains', [])),
        )));
    }

    private function phpBinary(): string
    {
        return (new PhpExecutableFinder)->find(false) ?: PHP_BINARY;
    }
}
