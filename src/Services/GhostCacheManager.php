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
        $this->contentService->forgetPostCache($canonicalUrl);

        return $variants;
    }

    /**
     * Purge slugs cache for a domain.
     */
    public function purgeSlugsCache(string $domain): void
    {
        Cache::forget("ghost:{$domain}:slugs");
    }

    public function purgeDataBlogCache(string $domain): void
    {
        Cache::forever(
            $this->contentService->blogGenerationKey($domain),
            Str::uuid()->toString(),
        );

        Log::info('Rotated Ghost blog cache generation.', [
            'domain' => $domain,
        ]);
    }
}
