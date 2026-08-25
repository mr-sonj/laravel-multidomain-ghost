<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Support;

use Illuminate\Filesystem\Filesystem;

final class DomainCacheFiles
{
    /**
     * Remove generated Laravel config, route and event caches for one domain.
     *
     * @return array<int, string> Paths which could not be deleted.
     */
    public static function clear(string $domain): array
    {
        $key = DomainName::dirKey($domain);
        $files = new Filesystem;
        $failed = [];

        foreach (glob(base_path("bootstrap/cache/*-{$key}.php")) ?: [] as $path) {
            $name = basename($path);

            if (preg_match('/^(?:config|events|routes(?:-v\d+)?)-'.preg_quote($key, '/').'\.php$/', $name) !== 1) {
                continue;
            }

            if (! $files->delete($path)) {
                $failed[] = $path;
            }
        }

        return $failed;
    }
}
