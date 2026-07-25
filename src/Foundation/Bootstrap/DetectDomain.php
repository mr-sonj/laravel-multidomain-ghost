<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Foundation\Bootstrap;

use Illuminate\Contracts\Foundation\Application;

class DetectDomain
{
    public function bootstrap(Application $app): void
    {
        if (method_exists($app, 'detectDomain')) {
            $app->detectDomain();
        }
    }
}
