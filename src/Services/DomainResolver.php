<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Services;

use Illuminate\Http\Request;
use MrSonj\MultiDomainGhost\Support\DomainName;

class DomainResolver
{
    private ?string $explicitDomain = null;

    /**
     * Resolve the current domain from request, CLI arguments, or environment.
     */
    public function resolve(?Request $request = null): string
    {
        return $this->determineDomain($request);
    }

    private function determineDomain(?Request $request = null): string
    {
        // 1. If domain was explicitly set via setDomain(), use it
        if ($this->explicitDomain !== null) {
            return $this->explicitDomain;
        }

        // 2. Use the opt-in multi-domain Application when the consumer enabled it.
        if (method_exists(app(), 'domain')) {
            $applicationDomain = app()->domain();

            if (is_string($applicationDomain) && $applicationDomain !== '') {
                return DomainName::normalize($applicationDomain);
            }
        }

        // 3. Check CLI arguments and web server globals.
        $globalDomain = DomainName::fromGlobals($_SERVER);
        if ($globalDomain !== null && ! in_array($globalDomain, ['localhost', '127.0.0.1'], true)) {
            return $globalDomain;
        }

        // 4. Resolve from passed Request or active HTTP Request
        $request ??= (app()->bound('request') ? request() : null);

        if ($request && method_exists($request, 'getHost')) {
            $host = $request->getHost();
            if (filled($host)) {
                return DomainName::normalize((string) $host);
            }
        }

        // 5. Fallback to server host even if localhost.
        if ($globalDomain !== null) {
            return $globalDomain;
        }

        // 6. Fallback to app.url domain or default
        $appUrl = (string) config('app.url', 'localhost');
        $parsedHost = parse_url($appUrl, PHP_URL_HOST);

        return DomainName::normalize($parsedHost ?: 'localhost');
    }

    /**
     * Override the domain (useful for testing or webhooks).
     */
    public function setDomain(string $domain): self
    {
        $this->explicitDomain = DomainName::normalize($domain);

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
    }
}
