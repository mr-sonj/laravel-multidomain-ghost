<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Services;

use Illuminate\Contracts\Cache\Repository;
use MrSonj\MultiDomainGhost\Client\GhostClient;
use MrSonj\MultiDomainGhost\Support\GhostCache;

class GhostContentService
{
    /**
     * Marks "Ghost answered, and this URL has no content". Cached separately from a
     * real result because Cache::remember() can never store a null: without this
     * sentinel every request for an unknown URL reaches Ghost again.
     */
    private const MISS = '__multidomain_ghost_miss__';

    private GhostClient $ghost;

    private bool $cacheEnabled;

    private string $domain;

    private ?string $blogGeneration = null;

    public function __construct(GhostClient $ghost, DomainResolver $domainResolver)
    {
        $this->cacheEnabled = GhostCache::enabled();
        $this->domain = $domainResolver->resolve();
        $this->ghost = $ghost;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function cacheEnabled(): bool
    {
        return $this->cacheEnabled;
    }

    /**
     * @deprecated Reads as "caching is off". Use cacheEnabled().
     */
    public function isLocal(): bool
    {
        return ! $this->cacheEnabled;
    }

    /**
     * The store Ghost content is cached in. Shared across domains on purpose so
     * that invalidation never depends on which domain is currently being served.
     */
    public function cache(): Repository
    {
        return GhostCache::repository();
    }

    private function cacheTtl(): int
    {
        return GhostCache::ttl();
    }

    public function dataBlog(int $page = 1, int $limit = 15): ?array
    {
        if (! $this->cacheEnabled) {
            return $this->ghost->list('tag:-hash-page', null, $page, $limit, include: 'tags,authors');
        }

        return $this->cache()->remember($this->blogCacheKey($page, $limit), $this->cacheTtl(), function () use ($page, $limit) {
            return $this->ghost->list('tag:-hash-page', null, $page, $limit, include: 'tags,authors');
        });
    }

    public function getPost(string $canonicalUrl): ?array
    {
        foreach ($this->canonicalUrlVariants($canonicalUrl) as $variant) {
            $content = $this->getPostByCanonicalUrl($variant);
            if ($content !== null) {
                if ($this->cacheEnabled && $variant !== $canonicalUrl) {
                    $this->cache()->put($this->postCacheKey($canonicalUrl), $content, $this->cacheTtl());
                }

                return $content;
            }
        }

        return null;
    }

    public function forgetPostCache(string $canonicalUrl): void
    {
        foreach ($this->canonicalUrlVariants($canonicalUrl) as $variant) {
            $this->cache()->forget($this->postCacheKey($variant));
        }
    }

    public function canonicalUrlVariants(string $canonicalUrl): array
    {
        $canonicalUrl = trim($canonicalUrl);
        if ($canonicalUrl === '') {
            return [];
        }

        return array_values(array_unique([
            $canonicalUrl,
            $this->alternateTrailingSlashUrl($canonicalUrl),
        ]));
    }

    private function getPostByCanonicalUrl(string $canonicalUrl): ?array
    {
        if (! $this->cacheEnabled) {
            return $this->ghost->content($canonicalUrl);
        }

        $key = $this->postCacheKey($canonicalUrl);
        $cached = $this->cache()->get($key);

        if ($cached !== null) {
            return $cached === self::MISS ? null : $cached;
        }

        $content = $this->ghost->content($canonicalUrl);

        $this->cache()->put(
            $key,
            $content ?? self::MISS,
            $content === null ? GhostCache::missTtl() : $this->cacheTtl(),
        );

        return $content;
    }

    private function alternateTrailingSlashUrl(string $canonicalUrl): string
    {
        $base = $canonicalUrl;
        $fragment = '';
        $query = '';
        $fragmentPosition = strpos($base, '#');
        if ($fragmentPosition !== false) {
            $fragment = substr($base, $fragmentPosition);
            $base = substr($base, 0, $fragmentPosition);
        }
        $queryPosition = strpos($base, '?');
        if ($queryPosition !== false) {
            $query = substr($base, $queryPosition);
            $base = substr($base, 0, $queryPosition);
        }
        $base = str_ends_with($base, '/') ? rtrim($base, '/') : $base.'/';

        return $base.$query.$fragment;
    }

    public function slugs(): array
    {
        if (! $this->cacheEnabled) {
            return $this->ghost->slugs();
        }

        $key = $this->slugsCacheKey();
        $cached = $this->cache()->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $slugs = $this->ghost->slugs();

        // An empty list is a legitimate answer, but it is also what a mistyped
        // domain tag or a revoked content key produces. Expire it quickly so the
        // site recovers on its own instead of serving an empty sitemap for a month.
        $this->cache()->put(
            $key,
            $slugs,
            $slugs === [] ? GhostCache::emptyTtl() : $this->cacheTtl(),
        );

        return $slugs;
    }

    public function forgetSlugsCache(): void
    {
        $this->cache()->forget($this->slugsCacheKey());
    }

    public function slugsCacheKey(): string
    {
        return self::slugsCacheKeyFor($this->domain);
    }

    public static function slugsCacheKeyFor(string $domain): string
    {
        return "ghost:{$domain}:slugs";
    }

    public function blogCacheKey(int $page, int $limit): string
    {
        // Read once per request: every listing page of a request shares one
        // generation, so re-reading it per key is a wasted round trip.
        $this->blogGeneration ??= (string) $this->cache()->get($this->blogGenerationKey($this->domain), '1');

        return "ghost:{$this->domain}:dataBlog:{$this->blogGeneration}:{$page}:{$limit}";
    }

    public function blogGenerationKey(string $domain): string
    {
        return "ghost:{$domain}:dataBlog:generation";
    }

    public function postCacheKey(string $canonicalUrl): string
    {
        $domain = parse_url($canonicalUrl, PHP_URL_HOST) ?: $this->domain;

        return "ghost:{$domain}:post:".sha1($canonicalUrl);
    }
}
