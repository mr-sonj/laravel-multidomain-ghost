<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Foundation\Console;

use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Bootstrap\SetRequestForConsole;
use MrSonj\MultiDomainGhost\Foundation\Bootstrap\DetectDomain;
use MrSonj\MultiDomainGhost\Foundation\Bootstrap\LoadDomainConfiguration;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Kernel extends \Illuminate\Foundation\Console\Kernel
{
    protected $bootstrappers = [
        DetectDomain::class,
        LoadEnvironmentVariables::class,
        LoadConfiguration::class,
        LoadDomainConfiguration::class,
        HandleExceptions::class,
        RegisterFacades::class,
        SetRequestForConsole::class,
        RegisterProviders::class,
        BootProviders::class,
    ];

    protected function getArtisan()
    {
        if (is_null($this->artisan)) {
            $this->artisan = (new Application($this->app, $this->events, $this->app->version()))
                ->resolveCommands($this->commands)
                ->setContainerCommandLoader();

            if ($this->symfonyDispatcher instanceof EventDispatcher) {
                $this->artisan->setDispatcher($this->symfonyDispatcher);
            }
        }

        return $this->artisan;
    }
}
