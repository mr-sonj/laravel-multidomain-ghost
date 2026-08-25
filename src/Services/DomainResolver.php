<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Services;

use Illuminate\Http\Request;
use MrSonj\MultiDomainGhost\Support\DomainName;

class DomainResolver
{
    private ?string $explicitDomain = null;

    private ?string $resolvedDomain = null;

    /**
     * Resolve the current domain from request, CLI arguments, or environment.
     */
    public function resolve(?Request $request = null): string
    {
        return $this->resolvedDomain ??= $this->determineDomain($request);
    }

    private function determineDomain(?Request $request = null): string
    {
        // 1. If domain was explicitly set via setDomain(), use it
        if ($this->explicitDomain !== null) {
            return $this->explicitDomain;
        }

        // 2. Use the opt-in multi-domain Application when the consumer enabled it.
        //    Its value already drives the storage path and config overrides, so it
        //    stays authoritative to keep every domain-scoped decision consistent.
        if (method_exists(app(), 'domain')) {
            $applicationDomain = app()->domain();

            if (is_string($applicationDomain) && $applicationDomain !== '') {
                $normalized = DomainName::normalize($applicationDomain);

                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        // 3. Prefer the active HTTP request. Its host has been through Laravel's
        //    host validation and honours trusted proxies, unlike the raw globals.
        $request ??= (app()->bound('request') ? request() : null);

        if ($request instanceof Request) {
            $host = DomainName::normalize((string) $request->getHost());

            if ($host !== '' && ! $this->isLoopback($host)) {
                return $host;
            }
        }

        // 4. Fall back to CLI arguments and web server globals.
        $globalDomain = DomainName::fromGlobals($_SERVER);
        if ($globalDomain !== null && ! $this->isLoopback($globalDomain)) {
            return $globalDomain;
        }

        // 5. Accept a loopback host rather than inventing one.
        if ($request instanceof Request && DomainName::normalize((string) $request->getHost()) !== '') {
            return DomainName::normalize((string) $request->getHost());
        }

        if ($globalDomain !== null) {
            return $globalDomain;
        }

        // 6. Fallback to app.url domain or default
        $appUrl = (string) config('app.url', 'localhost');
        $parsedHost = parse_url($appUrl, PHP_URL_HOST);

        return DomainName::normalize((string) ($parsedHost ?: 'localhost')) ?: 'localhost';
    }

    private function isLoopback(string $domain): bool
    {
        return in_array($domain, ['localhost', '127.0.0.1'], true);
    }

    /**
     * Override the domain (useful for testing or webhooks).
     */
    public function setDomain(string $domain): self
    {
        $this->explicitDomain = DomainName::normalize($domain);
        $this->resolvedDomain = null;

        return $this;
    }

    /**
     * Get the domain as a directory-safe string (dots to underscores).
     * Used for view paths, CSS files, controller namespaces.
     */
    public function dirKey(?string $domain = null): string
    {
        return DomainName::dirKey($domain ?? $this->resolve());
    }

    /**
     * Get the Ghost CMS domain tag slug.
     * Convention: dots become hyphens, prefixed with 'hash-'.
     * e.g. '10mailbox.com' => 'hash-10mailbox-com'
     */
    public static function domainTagSlug(string $domain): string
    {
        $prefix = config('multidomain-ghost.domain_tag_prefix', 'hash-');

        return $prefix.str_replace('.', '-', DomainName::normalize($domain));
    }

    public static function normalizeDomain(string $domain): string
    {
        return DomainName::normalize($domain);
    }

    public static function dirKeyFor(string $domain): string
    {
        return DomainName::dirKey($domain);
    }

    /**
     * Get the full Ghost filter tag for this domain.
     * e.g. 'tag:hash-10mailbox-com'
     */
    public function domainFilter(?string $domain = null): string
    {
        return 'tag:'.static::domainTagSlug($domain ?? $this->resolve());
    }

    /**
     * Reset the cached domain (for testing).
     */
    public function reset(): void
    {
        $this->explicitDomain = null;
        $this->resolvedDomain = null;
    }
}
