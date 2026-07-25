<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Foundation\Http;

use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use MrSonj\MultiDomainGhost\Foundation\Bootstrap\DetectDomain;
use MrSonj\MultiDomainGhost\Foundation\Bootstrap\LoadDomainConfiguration;

class Kernel extends \Illuminate\Foundation\Http\Kernel
{
    protected $bootstrappers = [
        DetectDomain::class,
        LoadEnvironmentVariables::class,
        LoadConfiguration::class,
        LoadDomainConfiguration::class,
        HandleExceptions::class,
        RegisterFacades::class,
        RegisterProviders::class,
        BootProviders::class,
    ];
}
