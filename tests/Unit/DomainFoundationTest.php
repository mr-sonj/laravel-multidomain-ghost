<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use MrSonj\MultiDomainGhost\Foundation\Application;
use MrSonj\MultiDomainGhost\Foundation\Bootstrap\LoadDomainConfiguration;
use MrSonj\MultiDomainGhost\Support\DomainName;
use PHPUnit\Framework\TestCase;

class DomainFoundationTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().'/multidomain-ghost-'.bin2hex(random_bytes(8));
        (new Filesystem)->makeDirectory($this->basePath.'/bootstrap/cache', 0755, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_domain_name_resolves_web_and_console_inputs(): void
    {
        $this->assertSame(
            'example.com',
            DomainName::fromGlobals(['HTTP_HOST' => 'Example.COM:8443']),
        );
        $this->assertSame(
            'worker.example.com',
            DomainName::fromGlobals([], ['artisan', 'queue:work', '--domain=worker.example.com']),
        );
        $this->assertSame(
            'worker.example.com',
            DomainName::fromGlobals([], ['artisan', 'queue:work', '--domain', 'worker.example.com']),
        );
        $this->assertTrue(DomainName::isRegistrable('example.com'));
        $this->assertFalse(DomainName::isRegistrable('../../storage'));
    }

    public function test_application_selects_domain_storage_and_bootstrap_cache_paths(): void
    {
        (new Filesystem)->makeDirectory($this->basePath.'/storage/example_com', 0755, true);

        $app = new Application($this->basePath);
        $app->useDomain('example.com');

        $this->assertSame('example.com', $app->domain());
        $this->assertTrue($app->domain('example.com'));
        $this->assertSame($this->basePath.'/storage/example_com', $app->storagePath());
        $this->assertStringEndsWith('bootstrap/cache/config-example_com.php', $app->getCachedConfigPath());
        $this->assertStringContainsString('routes-', $app->getCachedRoutesPath());
        $this->assertStringEndsWith('-example_com.php', $app->getCachedRoutesPath());
        $this->assertStringEndsWith('bootstrap/cache/events-example_com.php', $app->getCachedEventsPath());
    }

    public function test_application_keeps_shared_storage_when_domain_storage_is_absent(): void
    {
        $app = new Application($this->basePath);
        $app->useDomain('example.com');

        $this->assertSame($this->basePath.'/storage', $app->storagePath());
        $this->assertSame($this->basePath.'/bootstrap/cache/config.php', $app->getCachedConfigPath());
    }

    public function test_domain_configuration_is_loaded_after_base_configuration(): void
    {
        $files = new Filesystem;
        $files->makeDirectory($this->basePath.'/config/domains', 0755, true);
        $files->put(
            $this->basePath.'/config/domains/example_com.php',
            "<?php\n\nreturn ['app.name' => 'Example', 'cache.prefix' => 'example_cache'];\n",
        );

        $app = new Application($this->basePath);
        $app->instance('config', new Repository([
            'app' => ['name' => 'Base'],
            'cache' => ['prefix' => 'base_cache'],
        ]));
        $app->useDomain('example.com');

        (new LoadDomainConfiguration)->bootstrap($app);

        $this->assertSame('Example', $app['config']->get('app.name'));
        $this->assertSame('example_cache', $app['config']->get('cache.prefix'));
    }

    public function test_domain_configuration_is_skipped_when_the_domain_config_cache_is_in_use(): void
    {
        $files = new Filesystem;
        $files->makeDirectory($this->basePath.'/storage/example_com', 0755, true);
        $files->makeDirectory($this->basePath.'/config/domains', 0755, true);
        $files->put(
            $this->basePath.'/config/domains/example_com.php',
            "<?php\n\nreturn ['services.demo.key' => env('MULTIDOMAIN_GHOST_ABSENT_SECRET')];\n",
        );

        $app = new Application($this->basePath);
        $app->useDomain('example.com');

        // What `domain:optimize` wrote: the override already baked in, resolved
        // while .env was still being loaded.
        $files->put($app->getCachedConfigPath(), "<?php\n\nreturn [];\n");
        $app->instance('config', new Repository([
            'services' => ['demo' => ['key' => 'baked-at-cache-time']],
        ]));

        (new LoadDomainConfiguration)->bootstrap($app);

        $this->assertSame('baked-at-cache-time', $app['config']->get('services.demo.key'));
    }

    public function test_domain_configuration_is_applied_when_only_the_shared_config_cache_exists(): void
    {
        $files = new Filesystem;
        $files->makeDirectory($this->basePath.'/config/domains', 0755, true);
        $files->put(
            $this->basePath.'/config/domains/example_com.php',
            "<?php\n\nreturn ['app.name' => 'Example'];\n",
        );
        $files->put($this->basePath.'/bootstrap/cache/config.php', "<?php\n\nreturn [];\n");

        $app = new Application($this->basePath);
        $app->instance('config', new Repository(['app' => ['name' => 'Base']]));
        $app->useDomain('example.com');

        (new LoadDomainConfiguration)->bootstrap($app);

        $this->assertSame('Example', $app['config']->get('app.name'));
    }
}
