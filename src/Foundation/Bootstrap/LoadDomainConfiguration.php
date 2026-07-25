<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Foundation\Bootstrap;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use MrSonj\MultiDomainGhost\Support\DomainName;
use RuntimeException;

class LoadDomainConfiguration
{
    public function bootstrap(Application $app): void
    {
        if (! method_exists($app, 'domain') || ! $app->bound('config')) {
            return;
        }

        $domain = $app->domain();

        if (! is_string($domain) || $domain === '') {
            return;
        }

        $file = $app->configPath('domains/'.DomainName::dirKey($domain).'.php');

        if (! is_file($file)) {
            return;
        }

        $overrides = require $file;

        if (! is_array($overrides)) {
            throw new RuntimeException("Domain configuration [{$file}] must return an array.");
        }

        /** @var Repository $config */
        $config = $app->make('config');

        foreach ($overrides as $key => $value) {
            $config->set((string) $key, $value);
        }
    }
}
