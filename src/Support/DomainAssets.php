<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Support;

/**
 * The static files a single domain owns, under resources/views/{domain_key}/.
 *
 * They sit beside that domain's Blade files rather than in a directory of their
 * own: a domain already has exactly one folder under resources/, and robots.txt
 * is as much a part of what the domain publishes as its templates are. Kept out
 * of configuration because these are text files with formats of their own -
 * ads.txt is IAB's, llms.txt is a markdown convention - not values.
 */
final class DomainAssets
{
    public static function path(string $domain, string $file): string
    {
        return resource_path('views/'.DomainName::dirKey($domain).'/'.$file);
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
        // A host that is not a hostname has no directory key, which would leave
        // path() pointing at resources/views/{file} - a directory that holds the
        // application's own top-level views. No key, no file.
        if (DomainName::dirKey($domain) === '') {
            return null;
        }

        $path = self::path($domain, $file);

        if (! is_file($path)) {
            return null;
        }

        $contents = trim((string) file_get_contents($path));

        return $contents === '' ? null : $contents;
    }
}
