<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Services;

use Illuminate\Support\Str;
use MrSonj\MultiDomainGhost\Support\GhostCache;

/**
 * Invalidates Ghost content caches, including for domains other than the one
 * currently being served - which is the normal case for a webhook.
 */
class GhostCacheManager
{
    public function __construct(
        private GhostContentService $contentService
    ) {}

    /**
     * Purge post cache for a canonical URL and all its variants.
     *
     * @return array<int, string> the canonical URL variants that were purged
     */
    public function purgePostCache(string $canonicalUrl): array
    {
        $variants = $this->contentService->canonicalUrlVariants($canonicalUrl);

        $this->contentService->forgetPostCache($canonicalUrl);

        return $variants;
    }

    /**
     * Purge slugs cache for a domain.
     */
    public function purgeSlugsCache(string $domain): void
    {
        GhostCache::repository()->forget(GhostContentService::slugsCacheKeyFor($domain));
    }

    /**
     * Rotate the blog listing generation for a domain, invalidating every cached
     * page of its pagination at once without scanning the cache store for keys.
     */
    public function purgeDataBlogCache(string $domain): void
    {
        GhostCache::repository()->forever(
            $this->contentService->blogGenerationKey($domain),
            Str::uuid()->toString(),
        );
    }
}
