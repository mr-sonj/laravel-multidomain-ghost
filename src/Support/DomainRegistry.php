<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Support;

/**
 * The one answer to "which domains does this application serve".
 *
 * Registered domains are discovered directly from the existence of their
 * configuration override files (e.g. config/domains/example_com.php), which
 * Laravel automatically loads into the `domains` config repository.
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
        $keys = array_keys((array) config('domains', []));

        $domains = array_map(
            static fn (string|int $key): string => str_replace('_', '.', (string) $key),
            $keys,
        );

        return array_values(array_filter(array_unique(array_map(
            static fn (string $domain): string => DomainName::normalize($domain),
            $domains,
        )), static fn (string $domain): bool => $domain !== '' && DomainName::isRegistrable($domain)));
    }
}
