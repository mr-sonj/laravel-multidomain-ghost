<?php

namespace MrSonj\MultiDomainGhost\Tests;

use Illuminate\Filesystem\Filesystem;
use MrSonj\MultiDomainGhost\MultiDomainGhostServiceProvider;
use MrSonj\MultiDomainGhost\Support\DomainRegistry;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private ?string $temporaryConfigPath = null;

    protected function getPackageProviders($app): array
    {
        return [
            MultiDomainGhostServiceProvider::class,
        ];
    }

    /**
     * @param  array<string, array<mixed>>  $domains
     */
    protected function setRegisteredDomains(array $domains): void
    {
        DomainRegistry::flush();

        $files = new Filesystem;

        if ($this->temporaryConfigPath === null) {
            $this->temporaryConfigPath = sys_get_temp_dir().'/multidomain-ghost-config-'.bin2hex(random_bytes(8));
            $files->makeDirectory($this->temporaryConfigPath.'/domains', 0755, true);
            $this->app->useConfigPath($this->temporaryConfigPath);
        } else {
            $files->cleanDirectory($this->temporaryConfigPath.'/domains');
        }

        foreach ($domains as $key => $overrides) {
            $files->put(
                $this->temporaryConfigPath."/domains/{$key}.php",
                "<?php\n\nreturn ".var_export($overrides, true).";\n",
            );
        }

        $this->app['config']->set('domains', $domains);
    }

    protected function tearDown(): void
    {
        DomainRegistry::flush();

        if ($this->temporaryConfigPath !== null) {
            (new Filesystem)->deleteDirectory($this->temporaryConfigPath);
        }

        parent::tearDown();
    }
}
