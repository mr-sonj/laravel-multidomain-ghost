<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Foundation;

use Illuminate\Foundation\Application as LaravelApplication;
use MrSonj\MultiDomainGhost\Foundation\Configuration\ApplicationBuilder;
use MrSonj\MultiDomainGhost\Support\DomainName;

class Application extends LaravelApplication
{
    private ?string $activeDomain = null;

    public static function configure(?string $basePath = null): ApplicationBuilder
    {
        $basePath = is_string($basePath) ? $basePath : static::inferBasePath();

        return (new ApplicationBuilder(new static($basePath)))
            ->withKernels()
            ->withEvents()
            ->withCommands()
            ->withProviders();
    }

    public function detectDomain(?array $server = null, ?array $arguments = null): ?string
    {
        if ($this->activeDomain !== null) {
            return $this->activeDomain;
        }

        $domain = DomainName::fromGlobals($server ?? $_SERVER, $arguments ?? []);

        if ($domain === null || $domain === '') {
            return null;
        }

        return $this->useDomain($domain)->activeDomain;
    }

    public function useDomain(string $domain): static
    {
        $this->activeDomain = DomainName::normalize($domain);
        $this->instance('domain', $this->activeDomain);

        $domainStoragePath = $this->exactDomainStoragePath($this->activeDomain);

        if (is_dir($domainStoragePath)) {
            $this->useStoragePath($domainStoragePath);
        }

        return $this;
    }

    public function domain(string ...$domains): string|bool|null
    {
        $domain = $this->activeDomain ?? $this->detectDomain();

        return $domains === [] ? $domain : in_array($domain, $domains, true);
    }

    public function exactDomainStoragePath(?string $domain = null): string
    {
        $domain ??= $this->activeDomain ?? $this->detectDomain() ?? 'default';

        return $this->basePath('storage/'.DomainName::dirKey($domain));
    }

    public function getCachedConfigPath(): string
    {
        return $this->domainCachePath(parent::getCachedConfigPath());
    }

    public function getCachedRoutesPath(): string
    {
        return $this->domainCachePath(parent::getCachedRoutesPath());
    }

    public function getCachedEventsPath(): string
    {
        return $this->domainCachePath(parent::getCachedEventsPath());
    }

    private function domainCachePath(string $path): string
    {
        if ($this->activeDomain === null || ! is_dir($this->exactDomainStoragePath())) {
            return $path;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $suffix = '-'.DomainName::dirKey($this->activeDomain);

        if ($extension === '') {
            return $path.$suffix;
        }

        return substr($path, 0, -(strlen($extension) + 1)).$suffix.'.'.$extension;
    }
}
