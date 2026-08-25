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
            'config',
            'resources/css',
            'resources/views',
            'routes',
            'storage',
        ] as $directory) {
            $files->makeDirectory($this->basePath.'/'.$directory, 0755, true);
        }

        $files->put(
            $this->basePath.'/config/domain.php',
            "<?php\n\nreturn ['domains' => []];\n",
        );
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
        $domainConfig = require $this->basePath.'/config/domain.php';

        $this->assertSame('example.com', $domainConfig['domains']['example.com']);
    }

    public function test_domain_list_reports_the_effective_cache_prefix(): void
    {
        $this->app['config']->set('domain.domains', ['example.com' => 'example.com']);
        $this->app['config']->set('cache.prefix', 'shared_cache');

        $this->artisan('domain:list')
            ->expectsOutputToContain('shared_cache')
            ->assertExitCode(0);
    }

    public function test_domain_list_reports_a_domain_specific_cache_prefix_override(): void
    {
        $this->app['config']->set('domain.domains', ['example.com' => 'example.com']);
        $this->app['config']->set('cache.prefix', 'shared_cache');
        $this->app['config']->set('domains.example_com', ['cache.prefix' => 'example_com_cache']);

        $this->artisan('domain:list')
            ->expectsOutputToContain('example_com_cache')
            ->assertExitCode(0);
    }

    public function test_domain_list_reports_that_no_enricher_is_wired_up(): void
    {
        $this->app['config']->set('domain.domains', ['example.com' => 'example.com']);

        $this->artisan('domain:list')
            ->expectsOutputToContain('none')
            ->assertExitCode(0);
    }

    public function test_domain_list_warns_when_a_domain_points_at_a_different_default_cache_store(): void
    {
        $this->app['config']->set('domain.domains', [
            'example.com' => 'example.com',
            'other.com' => 'other.com',
        ]);
        $this->app['config']->set('cache.default', 'database');
        $this->app['config']->set('domains.other_com', ['cache.default' => 'redis']);

        $this->artisan('domain:list')
            ->expectsOutputToContain('Domain [other.com] overrides cache.default to [redis]')
            ->assertExitCode(0);
    }

    public function test_domain_list_stays_quiet_when_every_domain_shares_the_default_cache_store(): void
    {
        $this->app['config']->set('domain.domains', ['example.com' => 'example.com']);
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
