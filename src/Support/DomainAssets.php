<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Support;

/**
 * The static files a single domain owns, under resources/domains/{domain_key}/.
 *
 * The third of this package's three per-domain conventions, alongside
 * config/domains/ and routes/domains/. Kept apart from configuration because
 * robots.txt and ads.txt are text files with formats of their own, not values.
 */
final class DomainAssets
{
    public static function path(string $domain, string $file): string
    {
        return resource_path('domains/'.DomainName::dirKey($domain).'/'.$file);
    }

    /**
     * The file's trimmed contents - null when the domain has no such file, and
     * null when the file is empty.
     *
     * The two collapse deliberately. An empty ads.txt served with a 200 reads as
     * "this domain authorises no sellers", which is not the claim a domain
     * without an ads.txt is making, so an empty file must not produce a response.
     */
    public static function contents(string $domain, string $file): ?string
    {
        $path = self::path($domain, $file);

        if (! is_file($path)) {
            return null;
        }

        $contents = trim((string) file_get_contents($path));

        return $contents === '' ? null : $contents;
    }
}
