<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Support;

/**
 * The one answer to "which domains does this application serve".
 *
 * Registered domains are discovered directly from the existence of their
 * configuration override files (e.g. config/domains/example_com.php). Reading
 * the directory rather than the config repository keeps the registry accurate
 * even while Laravel has a stale configuration cache.
 */
final class DomainRegistry
{
    /**
     * Every registered hostname, normalized, de-duplicated and sorted by file name.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $files = glob(config_path('domains/*.php')) ?: [];
        sort($files, SORT_NATURAL);

        $domains = array_map(
            static fn (string $file): string => str_replace('_', '.', pathinfo($file, PATHINFO_FILENAME)),
            $files,
        );

        return array_values(array_filter(array_unique(array_map(
            static fn (string $domain): string => DomainName::normalize($domain),
            $domains,
        )), static fn (string $domain): bool => $domain !== '' && DomainName::isRegistrable($domain)));
    }

    public static function contains(string $domain): bool
    {
        $domain = DomainName::normalize($domain);

        return $domain !== '' && is_file(config_path('domains/'.DomainName::dirKey($domain).'.php'));
    }
}
