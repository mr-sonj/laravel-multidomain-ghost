<?php

namespace MrSonj\MultiDomainGhost\Tests;

use Illuminate\Filesystem\Filesystem;
use MrSonj\MultiDomainGhost\MultiDomainGhostServiceProvider;
use MrSonj\MultiDomainGhost\Support\DomainRegistry;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private ?string $temporaryConfigPath = null;

    private array $temporaryFiles = [];

    private array $temporaryDirectories = [];

    protected function getPackageProviders($app): array
    {
        return [
            MultiDomainGhostServiceProvider::class,
        ];
    }

    /**
     * Drop a key from the package config the way a published file that never
     * declared it would. Setting it to null is not the same thing: the key still
     * exists, so config()'s own default never applies.
     */
    protected function forgetConfigKey(string $key): void
    {
        $config = (array) $this->app['config']->get('multidomain-ghost');

        unset($config[$key]);

        $this->app['config']->set('multidomain-ghost', $config);
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
        $this->writeTemporaryFiles(base_path('routes/domains'), $files);
    }

    /**
     * @param  array<string, string>  $files  Paths relative to resources/domains.
     */
    protected function setDomainAssets(array $files): void
    {
        $this->writeTemporaryFiles(base_path('resources/domains'), $files);
    }

    private function writeTemporaryFiles(string $root, array $files): void
    {
        $fs = new Filesystem;

        foreach ($files as $name => $content) {
            $path = "{$root}/{$name}";

            foreach ([$root, dirname($path)] as $directory) {
                if (! is_dir($directory)) {
                    $fs->makeDirectory($directory, 0755, true);
                    $this->temporaryDirectories[] = $directory;
                }
            }

            $fs->put($path, $content);
            $this->temporaryFiles[] = $path;
        }
    }

    protected function tearDown(): void
    {
        DomainRegistry::flush();

        if ($this->temporaryConfigPath !== null) {
            (new Filesystem)->deleteDirectory($this->temporaryConfigPath);
        }

        $fs = new Filesystem;

        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                $fs->delete($file);
            }
        }

        $this->temporaryFiles = [];

        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            if (is_dir($directory) && $fs->isEmptyDirectory($directory)) {
                $fs->deleteDirectory($directory);
            }
        }

        $this->temporaryDirectories = [];

        parent::tearDown();
    }
}
