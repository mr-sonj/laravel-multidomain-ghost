<?php

namespace MrSonj\MultiDomainGhost\Tests;

use Illuminate\Filesystem\Filesystem;
use MrSonj\MultiDomainGhost\MultiDomainGhostServiceProvider;
use MrSonj\MultiDomainGhost\Support\DomainRegistry;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private ?string $temporaryConfigPath = null;

    private array $temporaryRouteFiles = [];

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

    protected function setDomainRouteFiles(array $files): void
    {
        $fs = new Filesystem;
        $dir = base_path('routes/domains');
        if (! is_dir($dir)) {
            $fs->makeDirectory($dir, 0755, true);
        }

        foreach ($files as $name => $content) {
            $path = "{$dir}/{$name}";
            $fs->put($path, $content);
            $this->temporaryRouteFiles[] = $path;
        }
    }

    protected function tearDown(): void
    {
        DomainRegistry::flush();

        if ($this->temporaryConfigPath !== null) {
            (new Filesystem)->deleteDirectory($this->temporaryConfigPath);
        }

        if ($this->temporaryRouteFiles !== []) {
            $fs = new Filesystem;
            foreach ($this->temporaryRouteFiles as $file) {
                if (is_file($file)) {
                    $fs->delete($file);
                }
            }
            $this->temporaryRouteFiles = [];
        }

        parent::tearDown();
    }
}
