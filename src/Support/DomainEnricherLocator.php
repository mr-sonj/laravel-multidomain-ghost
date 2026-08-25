<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Support;

use Illuminate\Support\Str;

/**
 * Finds the enricher class for a domain.
 *
 * Discovery is convention-first, but the convention embeds the domain key in a
 * PHP namespace - which cannot start with a digit or contain a hyphen. Domains
 * like `10mailbox.com` or `my-site.com` therefore have no expressible convention
 * name and must be mapped explicitly in `multidomain-ghost.enrichers`.
 */
final class DomainEnricherLocator
{
    /**
     * The conventional enricher class for a domain, or null when the domain key
     * cannot be expressed as a PHP identifier.
     */
    public static function conventionClassFor(string $domain): ?string
    {
        $dirKey = DomainName::dirKey($domain);

        if (! self::isIdentifier($dirKey)) {
            return null;
        }

        $studly = Str::studly($dirKey);

        if (! self::isIdentifier($studly)) {
            return null;
        }

        return "App\\Services\\{$dirKey}\\{$studly}Enricher";
    }

    /**
     * The enricher class to use for a domain: an explicit mapping first, then the
     * convention. Returns null when neither resolves to a class that exists.
     */
    public static function resolveClass(string $domain): ?string
    {
        $domain = DomainName::normalize($domain);
        $configured = (array) config('multidomain-ghost.enrichers', []);

        $mapped = $configured[$domain] ?? null;
        if (is_string($mapped) && $mapped !== '' && class_exists($mapped)) {
            return $mapped;
        }

        $convention = self::conventionClassFor($domain);

        return $convention !== null && class_exists($convention) ? $convention : null;
    }

    private static function isIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $value) === 1;
    }
}
