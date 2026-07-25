<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GhostCacheManager
{
    public function __construct(
        private GhostContentService $contentService
    ) {}

    /**
     * Purge post cache for a canonical URL and all its variants.
     */
    public function purgePostCache(string $canonicalUrl): array
    {
        $variants = $this->contentService->canonicalUrlVariants($canonicalUrl);

        $host = parse_url($canonicalUrl, PHP_URL_HOST);
        $domain = $host ? strtolower(preg_replace('/:\d+$/', '', (string) $host)) : null;

        $this->withDomainCachePrefix($domain, function () use ($canonicalUrl) {
            $this->contentService->forgetPostCache($canonicalUrl);
        });

        return $variants;
    }

    /**
     * Purge slugs cache for a domain.
     */
    public function purgeSlugsCache(string $domain): void
    {
        $this->withDomainCachePrefix($domain, function () use ($domain) {
            Cache::forget("ghost:{$domain}:slugs");
        });
    }

    public function purgeDataBlogCache(string $domain): void
    {
        $this->withDomainCachePrefix($domain, function () use ($domain) {
            Cache::forever(
                $this->contentService->blogGenerationKey($domain),
                Str::uuid()->toString(),
            );
        });

        Log::info('Rotated Ghost blog cache generation.', [
            'domain' => $domain,
        ]);
    }

    /**
     * Execute a callback under the target domain's cache prefix.
     */
    private function withDomainCachePrefix(?string $domain, \Closure $callback): mixed
    {
        if (empty($domain)) {
            return $callback();
        }

        $dirKey = str_replace('.', '_', strtolower($domain));
        $domainPrefix = config("domains.{$dirKey}.cache.prefix")
            ?? config("domains.{$dirKey}.cache_prefix")
            ?? "{$dirKey}_cache";

        $originalPrefix = config('cache.prefix');

        try {
            config(['cache.prefix' => $domainPrefix]);

            return $callback();
        } finally {
            config(['cache.prefix' => $originalPrefix]);
        }
    }
}
