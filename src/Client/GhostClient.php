<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Client;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use MrSonj\MultiDomainGhost\Contracts\ContentTransformerInterface;
use MrSonj\MultiDomainGhost\Services\DomainResolver;

class GhostClient
{
    private string $domainTag;

    public function __construct(
        private string $domain,
        private bool $usesAdminApi,
        private ?ContentTransformerInterface $contentTransformer = null,
    ) {
        $this->domainTag = DomainResolver::domainTagSlug($domain);
    }

    public static function domainTagSlug(string $domain): string
    {
        return DomainResolver::domainTagSlug($domain);
    }

    private function configValue(string $key, mixed $default = null): mixed
    {
        return config('multidomain-ghost.'.$key, config('services.ghost.'.$key, $default));
    }

    public function slugs(): array
    {
        $filter = 'visibility:public+canonical_url:-null';
        $fields = 'canonical_url,slug,codeinjection_head,updated_at,published_at';
        $posts = $this->listResource('posts', $filter, $fields, 1, 'all', false, null);
        $pages = $this->listResource('pages', $filter, $fields, 1, 'all', false, null);
        $links = [
            ...($posts['posts'] ?? []),
            ...($pages['pages'] ?? []),
        ];
        $uniqueLinks = [];

        foreach ($links as $link) {
            $canonicalUrl = (string) ($link['canonical_url'] ?? '');
            if ($canonicalUrl !== '') {
                $uniqueLinks[$canonicalUrl] = $link;
            }
        }

        return array_values($uniqueLinks);
    }

    public function pages(): ?array
    {
        $result = $this->listResource('pages', null, 'canonical_url');
        if ($result === null) {
            return null;
        }

        return array_column($result['pages'] ?? [], 'canonical_url');
    }

    public function list(?string $filter = null, ?string $fields = null, int $page = 1, int|string $limit = 'all', bool $modContent = true, ?string $include = 'tags'): ?array
    {
        return $this->listResource('posts', $filter, $fields, $page, $limit, $modContent, $include);
    }

    private function listResource(string $resource, ?string $filter = null, ?string $fields = null, int $page = 1, int|string $limit = 'all', bool $modContent = true, ?string $include = 'tags'): ?array
    {
        $query = [
            'filter' => $this->domainFilter($filter),
            'limit' => $limit,
            'page' => $page,
        ];
        if ($include !== null) {
            $query['include'] = $include;
        }
        if (! $this->usesAdminApi) {
            $query['key'] = $this->configValue('content_key');
        }
        if ($fields !== null) {
            $query['fields'] = $fields;
        }
        if ($this->usesAdminApi) {
            $query['formats'] = 'html';
        }
        $response = $this->request()->get($this->resourceEndpointFor($resource), $query);
        if (! $response->ok()) {
            return null;
        }
        $list = $response->json();
        if (! $modContent) {
            return $list;
        }
        foreach ($list[$resource] ?? [] as $key => $content) {
            $list[$resource][$key] = $this->mod_content($content);
        }

        return $list;
    }

    public function content(string $canonicalUrl): ?array
    {
        $query = [
            'include' => 'tags',
            'formats' => $this->usesAdminApi ? 'html' : null,
            'filter' => $this->domainFilter("canonical_url:'".$this->escapeFilterValue($canonicalUrl)."'"),
        ];
        if (! $this->usesAdminApi) {
            $query['key'] = $this->configValue('content_key');
        }
        foreach (['posts', 'pages'] as $resource) {
            $response = $this->request()->get(
                $this->resourceEndpointFor($resource),
                array_filter($query, fn ($value) => $value !== null),
            );
            if ($response->ok() && ! empty($response->json($resource))) {
                return $this->mod_content($response->json($resource.'.0'));
            }
        }

        return null;
    }

    public function mod_content(array $content): array
    {
        $content['domain'] = $this->domain;
        if (! empty($content['canonical_url'])) {
            $content['path'] = $this->url_to_path($content['canonical_url']);
            $content['url'] = $content['canonical_url'];
        }
        $content['schema'] = false;
        if (! empty($content['codeinjection_head']) && str_contains($content['codeinjection_head'], '<script type="application/ld+json">')) {
            $content['schema'] = true;
        }
        $content['primary_tag'] = $this->find_primary_tag($content);
        if ($content['primary_tag'] !== null) {
            foreach (['codeinjection_head', 'codeinjection_foot'] as $key) {
                if (! empty($content['primary_tag'][$key])) {
                    $content[$key] = ($content[$key] ?? '')."\n".$content['primary_tag'][$key];
                }
            }
        }

        return $this->contentTransformer?->transform($content, $this->domain) ?? $content;
    }

    public function find_primary_tag(array $content): ?array
    {
        foreach ($content['tags'] ?? [] as $tag) {
            if (($tag['slug'] ?? null) === $this->domainTag) {
                return $tag;
            }
        }

        return null;
    }

    public function url_to_path(string $url): string|false
    {
        $path = parse_url($url, PHP_URL_PATH);

        return $path ? ltrim(urldecode($path), '/') : false;
    }

    public function get_admin_token(?string $apiKey): string
    {
        if ($apiKey === null || ! str_contains($apiKey, ':')) {
            return '';
        }
        [$id, $secret] = explode(':', $apiKey, 2);
        $decodedSecret = hex2bin($secret);
        if ($decodedSecret === false) {
            return '';
        }
        $now = time();

        return JWT::encode([
            'iat' => $now,
            'exp' => $now + 300,
            'aud' => $this->configValue('jwt_audience', '/ghost/api/admin/'),
        ], $decodedSecret, 'HS256', null, [
            'alg' => 'HS256',
            'kid' => $id,
            'typ' => 'JWT',
        ]);
    }

    private function request(): PendingRequest
    {
        $this->ensureConfigured();

        $request = Http::acceptJson()
            ->withHeaders(['Accept-Version' => (string) $this->configValue('api_version', 'v6.0')])
            ->timeout((int) $this->configValue('timeout', 10))
            ->retry(
                (int) $this->configValue('retry_times', 2),
                (int) $this->configValue('retry_sleep', 200),
            );

        if (! (bool) $this->configValue('verify_ssl', true)) {
            $request = $request->withoutVerifying();
        }

        if ($this->usesAdminApi) {
            return $request->withHeaders([
                'Authorization' => 'Ghost '.$this->get_admin_token($this->configValue('admin_key')),
            ]);
        }

        return $request;
    }

    private function resourceEndpointFor(string $resource): string
    {
        if ($this->usesAdminApi) {
            return $this->resourceEndpoint((string) $this->configValue('admin_url'), 'admin', $resource);
        }

        return $this->resourceEndpoint((string) $this->configValue('url'), 'content', $resource);
    }

    private function domainFilter(?string $filter = null): string
    {
        $filters = ["tag:{$this->domainTag}"];
        if ($filter !== null && $filter !== '') {
            $filters[] = $filter;
        }

        return implode('+', $filters);
    }

    private function escapeFilterValue(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }

    private function resourceEndpoint(string $url, string $api, string $resource): string
    {
        $baseUrl = rtrim($url, '/');
        $baseUrl = preg_replace('#/ghost/api/v\d+(?:\.\d+)?$#', '/ghost/api', $baseUrl) ?? $baseUrl;

        if (str_ends_with($baseUrl, "/{$api}")) {
            return $baseUrl."/{$resource}";
        }

        if (str_ends_with($baseUrl, '/ghost/api')) {
            return $baseUrl."/{$api}/{$resource}";
        }

        return $baseUrl."/ghost/api/{$api}/{$resource}";
    }

    private function ensureConfigured(): void
    {
        $urlKey = $this->usesAdminApi ? 'admin_url' : 'url';
        $keyKey = $this->usesAdminApi ? 'admin_key' : 'content_key';

        if (! filled($this->configValue($urlKey))) {
            throw new \LogicException("Ghost configuration [multidomain-ghost.{$urlKey}] is required.");
        }

        if (! filled($this->configValue($keyKey))) {
            throw new \LogicException("Ghost configuration [multidomain-ghost.{$keyKey}] is required.");
        }
    }
}
