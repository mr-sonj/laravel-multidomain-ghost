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

        return filled($host) ? self::normalize((string) $host) : null;
    }

    public static function normalize(string $domain): string
    {
        $domain = strtolower(trim($domain));

        if (str_contains($domain, '://')) {
            $domain = (string) (parse_url($domain, PHP_URL_HOST) ?: $domain);
        } else {
            $domain = (string) (parse_url('//'.$domain, PHP_URL_HOST) ?: $domain);
        }

        return rtrim($domain, '.');
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
