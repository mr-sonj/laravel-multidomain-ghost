<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use MrSonj\MultiDomainGhost\Support\BootstrapAppPatcher;
use PHPUnit\Framework\TestCase;

class BootstrapAppPatcherTest extends TestCase
{
    public function test_patches_the_standard_laravel_application_import(): void
    {
        $bootstrap = <<<'PHP'
<?php

use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))->create();
PHP;

        $patched = BootstrapAppPatcher::patch($bootstrap);

        $this->assertIsString($patched);
        $this->assertStringContainsString(
            'use MrSonj\MultiDomainGhost\Foundation\Application;',
            $patched,
        );
        $this->assertStringContainsString('return Application::configure(', $patched);
        $this->assertSame($patched, BootstrapAppPatcher::patch($patched));
    }

    public function test_preserves_an_application_import_alias(): void
    {
        $bootstrap = <<<'PHP'
<?php

use Illuminate\Foundation\Application as LaravelApplication;

return LaravelApplication::configure(basePath: dirname(__DIR__))->create();
PHP;

        $patched = BootstrapAppPatcher::patch($bootstrap);

        $this->assertIsString($patched);
        $this->assertStringContainsString(
            'use MrSonj\MultiDomainGhost\Foundation\Application as LaravelApplication;',
            $patched,
        );
    }

    public function test_patches_a_fully_qualified_application_call(): void
    {
        $bootstrap = <<<'PHP'
<?php

return Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))->create();
PHP;

        $patched = BootstrapAppPatcher::patch($bootstrap);

        $this->assertIsString($patched);
        $this->assertStringContainsString(
            '\MrSonj\MultiDomainGhost\Foundation\Application::configure(',
            $patched,
        );
    }

    public function test_rejects_an_unknown_bootstrap_structure(): void
    {
        $this->assertNull(BootstrapAppPatcher::patch('<?php return new stdClass;'));
    }

    public function test_does_not_treat_an_unrelated_package_class_reference_as_installed(): void
    {
        $bootstrap = <<<'PHP'
<?php

// MrSonj\MultiDomainGhost\Foundation\Application
return new stdClass;
PHP;

        $this->assertNull(BootstrapAppPatcher::patch($bootstrap));
    }
}
