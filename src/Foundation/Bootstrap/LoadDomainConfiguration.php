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

        if ($this->domainCacheIsInUse($app, $domain)) {
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

    /**
     * Whether the configuration just loaded already carries this domain's overrides.
     *
     * A domain-scoped config cache is written by a full kernel bootstrap, so the
     * overrides are baked into it with every env() resolved while .env was still
     * being read. Re-applying the raw file on top would overwrite those values
     * with null, because LoadEnvironmentVariables returns early once config is
     * cached and .env is never loaded again.
     *
     * Guarded on the cached path actually being domain-suffixed: a bare
     * `config:cache` writes to the shared path, and that file never went through
     * this bootstrapper, so its overrides still have to be applied here.
     */
    private function domainCacheIsInUse(Application $app, string $domain): bool
    {
        if (! method_exists($app, 'configurationIsCached') || ! method_exists($app, 'getCachedConfigPath')) {
            return false;
        }

        if (! $app->configurationIsCached()) {
            return false;
        }

        return str_ends_with(
            (string) pathinfo((string) $app->getCachedConfigPath(), PATHINFO_FILENAME),
            '-'.DomainName::dirKey($domain),
        );
    }
}
