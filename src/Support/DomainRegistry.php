<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Support;

/**
 * The one answer to "which domains does this application serve".
 *
 * Two registries exist in the wild: the env-driven package allowlist, and the
 * config/domain.php file `domain:add` maintains. Reading them in three separate
 * places is how `domain:list` came to disagree with `domain:optimize` and the
 * webhook about a domain registered through the environment.
 */
final class DomainRegistry
{
    /**
     * Every registered hostname, normalized, de-duplicated and in source order.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $allowlist = array_values(array_filter((array) config('multidomain-ghost.domains', [])));

        $source = $allowlist !== []
            ? $allowlist
            : array_keys((array) config('domain.domains', []));

        return array_values(array_unique(array_filter(array_map(
            static fn ($domain): string => DomainName::normalize((string) $domain),
            $source,
        ))));
    }

    public static function has(string $domain): bool
    {
        return in_array(DomainName::normalize($domain), self::all(), true);
    }

    public static function isEmpty(): bool
    {
        return self::all() === [];
    }
}
