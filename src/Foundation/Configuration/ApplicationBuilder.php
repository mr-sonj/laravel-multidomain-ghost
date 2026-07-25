<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Foundation\Configuration;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Configuration\ApplicationBuilder as LaravelApplicationBuilder;
use MrSonj\MultiDomainGhost\Foundation\Console\Kernel as ConsoleKernel;
use MrSonj\MultiDomainGhost\Foundation\Http\Kernel as HttpKernel;

class ApplicationBuilder extends LaravelApplicationBuilder
{
    public function withKernels(): static
    {
        $this->app->singleton(
            Kernel::class,
            HttpKernel::class,
        );

        $this->app->singleton(
            \Illuminate\Contracts\Console\Kernel::class,
            ConsoleKernel::class,
        );

        return $this;
    }
}
