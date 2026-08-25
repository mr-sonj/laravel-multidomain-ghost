<?php

namespace MrSonj\MultiDomainGhost\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class DomainCommandsTest extends TestCase
{
    private string $basePath;

    private string $originalBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalBasePath = $this->app->basePath();
        $this->basePath = sys_get_temp_dir().'/multidomain-ghost-command-'.bin2hex(random_bytes(8));
        $files = new Filesystem;

        foreach ([
            'bootstrap/cache',
            'config/domains',
            'resources/css',
            'resources/views',
            'routes',
            'storage',
        ] as $directory) {
            $files->makeDirectory($this->basePath.'/'.$directory, 0755, true);
        }

        $files->put(
            $this->basePath.'/routes/web.php',
            "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n",
        );
        $files->put(
            $this->basePath.'/vite.config.js',
            "export default { input: ['resources/css/app.css'] };\n",
        );

        $this->app->setBasePath($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBasePath);
        (new Filesystem)->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_domain_add_generates_a_self_contained_domain_scaffold(): void
    {
        $this->artisan('domain:add', ['domain' => 'example.com'])
            ->assertExitCode(0);

        $files = new Filesystem;
        $viewDirectory = $this->basePath.'/resources/views/example_com';

        foreach (['main', 'home', 'page', 'blog', 'post', 'contact'] as $view) {
            $this->assertFileExists("{$viewDirectory}/{$view}.blade.php");
        }

        $this->assertStringNotContainsString(
            'App\Helper',
            $files->get("{$viewDirectory}/main.blade.php")
                .$files->get("{$viewDirectory}/home.blade.php"),
        );
        $this->assertStringContainsString(
            "@extends('example_com.main')",
            $files->get("{$viewDirectory}/home.blade.php"),
        );
        $this->assertStringContainsString(
            "'blog'",
            $files->get($this->basePath.'/routes/web.php'),
        );
        $this->assertStringContainsString(
            "'resources/css/example_com.css'",
            $files->get($this->basePath.'/vite.config.js'),
        );

        $this->assertFileExists($this->basePath.'/config/domains/example_com.php');
        $domainConfig = require $this->basePath.'/config/domains/example_com.php';
        $this->assertSame('https://example.com', $domainConfig['app.url']);
    }

    public function test_domain_remove_deletes_config_override(): void
    {
        $files = new Filesystem;
        $configFile = $this->basePath.'/config/domains/example_com.php';
        $files->put($configFile, "<?php\nreturn [];\n");

        $this->artisan('domain:remove', ['domain' => 'example.com'])
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($configFile);
    }

    public function test_domain_remove_with_force_deletes_storage(): void
    {
        $files = new Filesystem;
        $configFile = $this->basePath.'/config/domains/example_com.php';
        $storageDir = $this->basePath.'/storage/example_com';
        $files->put($configFile, "<?php\nreturn [];\n");
        $files->makeDirectory($storageDir, 0755, true);

        $this->artisan('domain:remove', ['domain' => 'example.com', '--force' => true])
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($configFile);
        $this->assertDirectoryDoesNotExist($storageDir);
    }

    public function test_domain_list_reports_the_effective_cache_prefix(): void
    {
        $this->app['config']->set('domains', ['example_com' => []]);
        $this->app['config']->set('cache.prefix', 'shared_cache');

        $this->artisan('domain:list')
            ->expectsOutputToContain('shared_cache')
            ->assertExitCode(0);
    }

    public function test_domain_list_reports_a_domain_specific_cache_prefix_override(): void
    {
        $this->app['config']->set('domains', [
            'example_com' => ['cache.prefix' => 'example_com_cache'],
        ]);
        $this->app['config']->set('cache.prefix', 'shared_cache');

        $this->artisan('domain:list')
            ->expectsOutputToContain('example_com_cache')
            ->assertExitCode(0);
    }

    public function test_domain_list_reports_that_no_enricher_is_wired_up(): void
    {
        $this->app['config']->set('domains', ['example_com' => []]);

        $this->artisan('domain:list')
            ->expectsOutputToContain('none')
            ->assertExitCode(0);
    }

    public function test_domain_list_warns_when_a_domain_points_at_a_different_default_cache_store(): void
    {
        $this->app['config']->set('domains', [
            'example_com' => [],
            'other_com' => ['cache.default' => 'redis'],
        ]);
        $this->app['config']->set('cache.default', 'database');

        $this->artisan('domain:list')
            ->expectsOutputToContain('Domain [other.com] overrides cache.default to [redis]')
            ->assertExitCode(0);
    }

    public function test_domain_list_stays_quiet_when_every_domain_shares_the_default_cache_store(): void
    {
        $this->app['config']->set('domains', ['example_com' => []]);
        $this->app['config']->set('cache.default', 'database');

        $this->artisan('domain:list')
            ->doesntExpectOutputToContain('cache.default')
            ->assertExitCode(0);
    }

    public function test_domain_add_routes_the_www_host_to_the_apex(): void
    {
        $this->artisan('domain:add', ['domain' => 'example.com'])->assertExitCode(0);

        $routes = (new Filesystem)->get($this->basePath.'/routes/web.php');

        $this->assertStringContainsString("Route::domain('www.example.com')", $routes);
    }
}
