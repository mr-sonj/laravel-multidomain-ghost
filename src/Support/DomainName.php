<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Support;

final class DomainName
{
    public static function fromGlobals(array $server = [], array $arguments = []): ?string
    {
        $arguments = $arguments ?: (array) ($server['argv'] ?? []);

        foreach ($arguments as $index => $argument) {
            if (! is_string($argument)) {
                continue;
            }

            if (str_starts_with($argument, '--domain=')) {
                return self::normalize(substr($argument, 9));
            }

            if ($argument === '--domain' && isset($arguments[$index + 1])) {
                return self::normalize((string) $arguments[$index + 1]);
            }
        }

        $host = $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? null;

        if (! filled($host)) {
            return null;
        }

        return self::normalize((string) $host) ?: null;
    }

    /**
     * Reduce any host-ish input to a bare, lowercase hostname.
     *
     * Returns an empty string for anything that is not a usable hostname. The
     * value ends up in cache keys, storage paths and config lookups, and it can
     * originate from an unvalidated Host header, so a syntactically impossible
     * host must not be passed through verbatim.
     */
    public static function normalize(string $domain): string
    {
        $domain = strtolower(trim($domain));

        if (str_contains($domain, '://')) {
            $domain = (string) (parse_url($domain, PHP_URL_HOST) ?: $domain);
        } else {
            $domain = (string) (parse_url('//'.$domain, PHP_URL_HOST) ?: $domain);
        }

        $domain = rtrim($domain, '.');

        return self::isHostname($domain) ? $domain : '';
    }

    /**
     * Whether the value is shaped like a hostname: labels of letters, digits and
     * hyphens separated by dots, or a bracketed IPv6 literal.
     */
    public static function isHostname(string $domain): bool
    {
        if ($domain === '' || strlen($domain) > 253) {
            return false;
        }

        if (str_starts_with($domain, '[') && str_ends_with($domain, ']')) {
            return filter_var(trim($domain, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $domain) === 1;
    }

    public static function dirKey(string $domain): string
    {
        return str_replace('.', '_', self::normalize($domain));
    }

    public static function isRegistrable(string $domain): bool
    {
        $domain = self::normalize($domain);

        return preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
            $domain,
        ) === 1;
    }
}
