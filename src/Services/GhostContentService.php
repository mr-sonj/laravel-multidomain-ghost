<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Services;

use Illuminate\Support\Facades\Cache;
use MrSonj\MultiDomainGhost\Client\GhostClient;

class GhostContentService
{
    private GhostClient $ghost;

    private bool $isLocal;

    private string $domain;

    public function __construct(GhostClient $ghost, DomainResolver $domainResolver)
    {
        $this->isLocal = app()->environment('local');
        $this->domain = $domainResolver->resolve();
        $this->ghost = $ghost;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function isLocal(): bool
    {
        return $this->isLocal;
    }

    private function cacheTtl(): int
    {
        return (int) config('multidomain-ghost.cache_ttl', 60 * 60 * 24 * 30);
    }

    public function dataBlog(int $page = 1, int $limit = 15): ?array
    {
        if ($this->isLocal) {
            return $this->ghost->list('tag:-hash-page', null, $page, $limit, include: 'tags,authors');
        }

        return Cache::remember($this->blogCacheKey($page, $limit), $this->cacheTtl(), function () use ($page, $limit) {
            return $this->ghost->list('tag:-hash-page', null, $page, $limit, include: 'tags,authors');
        });
    }

    public function getPost(string $canonicalUrl): ?array
    {
        foreach ($this->canonicalUrlVariants($canonicalUrl) as $variant) {
            $content = $this->getPostByCanonicalUrl($variant);
            if ($content !== null) {
                if (! $this->isLocal && $variant !== $canonicalUrl) {
                    Cache::put($this->postCacheKey($canonicalUrl), $content, $this->cacheTtl());
                }

                return $content;
            }
        }

        return null;
    }

    public function forgetPostCache(string $canonicalUrl): void
    {
        foreach ($this->canonicalUrlVariants($canonicalUrl) as $variant) {
            Cache::forget($this->postCacheKey($variant));
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
        if ($this->isLocal) {
            return $this->ghost->content($canonicalUrl);
        }

        return Cache::remember($this->postCacheKey($canonicalUrl), $this->cacheTtl(), function () use ($canonicalUrl) {
            return $this->ghost->content($canonicalUrl);
        });
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
        if ($this->isLocal) {
            return $this->ghost->slugs();
        }

        return Cache::remember($this->slugsCacheKey(), $this->cacheTtl(), function () {
            return $this->ghost->slugs();
        });
    }

    public function forgetSlugsCache(): void
    {
        Cache::forget($this->slugsCacheKey());
    }

    public function slugsCacheKey(): string
    {
        return "ghost:{$this->domain}:slugs";
    }

    public function blogCacheKey(int $page, int $limit): string
    {
        $generation = Cache::get($this->blogGenerationKey($this->domain), '1');

        return "ghost:{$this->domain}:dataBlog:{$generation}:{$page}:{$limit}";
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
