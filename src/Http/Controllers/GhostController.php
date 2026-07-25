<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use MrSonj\MultiDomainGhost\Contracts\DomainEnricherInterface;
use MrSonj\MultiDomainGhost\Events\GhostPostUpdated;
use MrSonj\MultiDomainGhost\Services\GhostCacheManager;
use MrSonj\MultiDomainGhost\Services\GhostContentService;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class GhostController extends Controller
{
    private string $domain;

    public function __construct(
        private DomainEnricherInterface $enricher,
        private GhostContentService $ghostContentService
    ) {
        $this->domain = $ghostContentService->domain();
    }

    /**
     * Build SEO data array from content.
     * The consuming app is responsible for rendering this into meta tags.
     */
    public function seoData(array $content): array
    {
        $title = $this->seoValue($content, 'meta_title')
            ?? $this->seoValue($content, 'title')
            ?? config('app.name', '');

        $description = $this->seoValue($content, 'meta_description')
            ?? $this->seoValue($content, 'excerpt')
            ?? '';
        $urlDirImage = 'https://'.$content['domain'].'/img/'.str_replace('.', '_', $content['domain']);
        $image = $this->seoValue($content, 'feature_image') ?? $urlDirImage.'/apple-touch-icon.png';
        $isPage = isset($content['tags']) && collect($content['tags'])->pluck('name')->contains('#page');
        $contentType = $isPage ? 'WebPage' : 'Article';
        $primaryTagData = $this->primaryTagData($content);

        return [
            'title' => $title,
            'description' => $description,
            'canonical_url' => $content['canonical_url'] ?? '',
            'image' => $image,
            'type' => $contentType,
            'is_page' => $isPage,
            'og' => [
                'title' => $this->seoValue($content, 'og_title') ?? $title,
                'description' => $this->seoValue($content, 'og_description') ?? $description,
                'image' => $this->seoValue($content, 'og_image') ?? $image,
                'type' => $isPage ? 'website' : 'article',
                'url' => $content['canonical_url'] ?? '',
                'updated_time' => $content['updated_at'] ?? now()->toISOString(),
                'site_name' => $primaryTagData['facebook'] ?? null,
                'locale' => $primaryTagData['locale'] ?? null,
            ],
            'twitter' => [
                'title' => $this->seoValue($content, 'twitter_title') ?? $title,
                'description' => $this->seoValue($content, 'twitter_description') ?? $description,
                'image' => $this->seoValue($content, 'twitter_image') ?? $image,
                'card' => 'summary_large_image',
                'url' => $content['canonical_url'] ?? '',
                'site' => $primaryTagData['x'] ?? null,
            ],
            'json_ld' => [
                'has_custom_schema' => $content['schema'] ?? false,
                'title' => $title,
                'description' => $description,
                'type' => $contentType,
                'url' => $content['canonical_url'] ?? '',
                'image' => $image,
                'published_at' => $content['published_at'] ?? null,
                'updated_at' => $content['updated_at'] ?? null,
                'language' => $primaryTagData['locale'] ?? 'en-US',
                'is_part_of' => 'https://'.$content['domain'].'/#website',
            ],
            'primary_tag_data' => $primaryTagData,
        ];
    }

    public function dataBlog(int $page = 1, int $limit = 15): ?array
    {
        return $this->ghostContentService->dataBlog($page, $limit);
    }

    public function getPost(string $canonicalUrl): ?array
    {
        return $this->ghostContentService->getPost($canonicalUrl);
    }

    public function content(Request $request): array
    {
        $canonicalUrl = $this->canonicalUrl($request);
        $content = $this->getPost($canonicalUrl);

        if ($content === null) {
            abort(404);
        }

        return $this->enricher->enrich($content, $canonicalUrl);
    }

    public function page(Request $request): View
    {
        $viewPath = $request->route('viewPath')
            ?: config('multidomain-ghost.views.page', 'multidomain-ghost::page');
        $content = $this->content($request);
        $seo = $this->seoData($content);

        return view($viewPath)->with([
            'content' => $content,
            'seo' => $seo,
        ]);
    }

    public function blog(Request $request): View
    {
        $page = max(1, (int) $request->integer('page', 1));
        $dataBlog = $this->dataBlog($page, 15);

        if ($dataBlog === null) {
            abort(404);
        }

        $canonicalUrl = $this->canonicalUrl($request);
        $siteName = trim((string) config('app.name')) ?: $this->domain;
        $content = $this->getPost($canonicalUrl) ?? [
            'domain' => $this->domain,
            'title' => $siteName.' Blog',
            'canonical_url' => $canonicalUrl,
            'tags' => [],
        ];
        $content = $this->enricher->enrich($content, $canonicalUrl);
        $viewPath = $request->route('viewPath')
            ?: config('multidomain-ghost.views.blog', 'multidomain-ghost::blog');

        return view($viewPath)->with([
            'content' => $content,
            'seo' => $this->seoData($content),
            'dataBlog' => $dataBlog,
            'page' => $page,
        ]);
    }

    public function robots(): Response
    {
        $robots = "User-agent: *\nDisallow: /cdn-cgi/\nSitemap: https://".$this->domain.'/sitemap.xml';

        $contentSignal = config('multidomain-ghost.robots.content_signal')
            ?: config('services.robots.content_signal');
        if (! empty($contentSignal)) {
            $robots .= "\nContent-Signal: ".$contentSignal;
        }

        return response(trim($robots))->header('Content-Type', 'text/plain;charset=UTF-8');
    }

    public function ads(): Response
    {
        $ads = config('services.adsense.ads_txt', '');

        return response(trim($ads))->header('Content-Type', 'text/plain;charset=UTF-8');
    }

    /**
     * Return normalized, indexable Ghost URLs for an application-owned sitemap renderer.
     */
    public function sitemapLinks(): array
    {
        $ghostSlugs = $this->ghostContentService->slugs();
        $links = [];

        foreach ($ghostSlugs as $value) {
            if (! empty($value['codeinjection_head']) && stripos($value['codeinjection_head'], 'noindex') !== false) {
                continue;
            }

            $url = trim((string) ($value['canonical_url'] ?? ''));
            if ($url === '' || str_contains($url, '{')) {
                continue;
            }

            $links[] = [
                'url' => $url,
                'slug' => $value['slug'] ?? null,
                'updated_at' => $value['updated_at'] ?? null,
                'published_at' => $value['published_at'] ?? null,
            ];
        }

        return $links;
    }

    public function sitemap(): SymfonyResponse
    {
        $urls = collect($this->sitemapLinks())
            ->map(function (array $link): string {
                $lastModified = $link['updated_at'] ?? $link['published_at'] ?? null;
                $lastModifiedXml = filled($lastModified)
                    ? "\n        <lastmod>".$this->xml((string) $lastModified).'</lastmod>'
                    : '';

                return '    <url>'
                    ."\n        <loc>".$this->xml((string) $link['url']).'</loc>'
                    .$lastModifiedXml
                    ."\n    </url>";
            })
            ->implode("\n");

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            ."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .($urls !== '' ? "\n{$urls}" : '')
            ."\n</urlset>\n";

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    /**
     * Return normalized feed data for an application-owned RSS/Atom renderer.
     */
    public function feedData(Request $request): array
    {
        $page = (int) $request->get('page', 1);
        if ($page < 1) {
            $page = 1;
        }
        $dataBlog = $this->dataBlog($page, 15);
        if ($dataBlog === null) {
            abort(404);
        }

        return [
            'domain' => $this->domain,
            'dataBlog' => $dataBlog,
            'page' => $page,
        ];
    }

    public function feed(Request $request): SymfonyResponse
    {
        $feed = $this->feedData($request);
        $siteUrl = 'https://'.$this->domain;
        $channelTitle = (string) config('app.name', $this->domain);
        $items = collect($feed['dataBlog']['posts'] ?? [])
            ->filter(fn (array $post): bool => filled($post['canonical_url'] ?? $post['url'] ?? null))
            ->map(function (array $post): string {
                $url = (string) ($post['canonical_url'] ?? $post['url'] ?? '');
                $title = (string) ($post['title'] ?? '');
                $description = (string) ($post['excerpt'] ?? $post['custom_excerpt'] ?? '');
                $publishedAt = $this->rssDate($post['published_at'] ?? null);
                $publishedXml = $publishedAt !== null
                    ? "\n            <pubDate>".$this->xml($publishedAt).'</pubDate>'
                    : '';

                return '        <item>'
                    ."\n            <title>".$this->xml($title).'</title>'
                    ."\n            <link>".$this->xml($url).'</link>'
                    ."\n            <guid isPermaLink=\"true\">".$this->xml($url).'</guid>'
                    ."\n            <description>".$this->xml($description).'</description>'
                    .$publishedXml
                    ."\n        </item>";
            })
            ->implode("\n");

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            ."\n".'<rss version="2.0">'
            ."\n    <channel>"
            ."\n        <title>".$this->xml($channelTitle).'</title>'
            ."\n        <link>".$this->xml($siteUrl).'</link>'
            ."\n        <description>".$this->xml($channelTitle.' feed').'</description>'
            .($items !== '' ? "\n{$items}" : '')
            ."\n    </channel>"
            ."\n</rss>\n";

        return response($xml, 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
        ]);
    }

    public function postWebhook(Request $request, GhostCacheManager $cacheManager): JsonResponse
    {
        if (! $this->validWebhook($request)) {
            abort(403);
        }

        $data = $request->all();
        $nameWebhook = $request->input('name');
        $contentType = isset($data['page']) ? 'page' : 'post';
        $payload = $data[$contentType] ?? [];
        $posts = collect([
            $payload['current'] ?? null,
            $payload['previous'] ?? null,
        ])->filter();

        if ($posts->isEmpty()) {
            return response()->json([
                'message' => 'No post payload found.',
            ], 422);
        }

        $cacheCleared = [];
        $domainsToClear = [];
        $packageDomains = (array) config('multidomain-ghost.domains', []);
        $registeredDomains = array_map(
            'strtolower',
            ! empty($packageDomains) ? $packageDomains : array_keys(config('domain.domains', [])),
        );
        $enforceDomainAllowlist = ! empty($registeredDomains);

        foreach ($posts->pluck('canonical_url')->filter()->unique() as $canonicalUrl) {
            $variants = $cacheManager->purgePostCache($canonicalUrl);
            array_push($cacheCleared, ...$variants);

            $host = parse_url($canonicalUrl, PHP_URL_HOST);
            if ($host) {
                $domain = preg_replace('/:\d+$/', '', $host);
                $domain = strtolower($domain);
                if (! $enforceDomainAllowlist || in_array($domain, $registeredDomains, true)) {
                    $domainsToClear[] = $domain;
                }
            }
        }

        $domainsToClear = array_unique($domainsToClear);

        if (empty($domainsToClear)) {
            return response()->json([
                'message' => 'Ignored. Domain not registered in this application.',
                'cache_cleared' => $cacheCleared,
            ]);
        }

        foreach ($domainsToClear as $domainToClear) {
            $cacheManager->purgeSlugsCache($domainToClear);
            $cacheCleared[] = "{$domainToClear}:slugs";
        }

        $isPage = $contentType === 'page' || $posts->contains(function (array $post) {
            return isset($post['tags']) && collect($post['tags'])->pluck('name')->contains('#page');
        });

        if (! $isPage) {
            foreach ($domainsToClear as $domainToClear) {
                $cacheManager->purgeDataBlogCache($domainToClear);
                $cacheCleared[] = "{$domainToClear}:dataBlog_pagination";
                Log::info("Cleared dataBlog cache for webhook: {$nameWebhook}", [
                    'domain' => $domainToClear,
                ]);
            }
        }

        Event::dispatch(new GhostPostUpdated(
            $nameWebhook ?? 'post.updated',
            $isPage ? 'page' : 'post',
            $cacheCleared,
            $domainsToClear
        ));

        return response()->json([
            'webhook' => $nameWebhook,
            'content_type' => $isPage ? 'page' : 'post',
            'cache_cleared' => $cacheCleared,
        ]);

    }

    private function canonicalUrl(Request $request): string
    {
        $path = $request->getPathInfo() === '/' ? '' : $request->getPathInfo();

        return 'https://'.$request->getHost().$path;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function rssDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format(DATE_RSS);
        } catch (\Exception) {
            return null;
        }
    }

    private function seoValue(array $content, string $key): mixed
    {
        return $this->filledSeoValue($content[$key] ?? null)
            ?? $this->filledSeoValue($content['primary_tag'][$key] ?? null);
    }

    private function filledSeoValue(mixed $value): mixed
    {
        if ($value === null || $value === false || $value === []) {
            return null;
        }
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }

    private function primaryTagData(array $content): array
    {
        if (empty($content['primary_tag']['description'])) {
            return [];
        }
        $data = json_decode($content['primary_tag']['description'], true);

        return is_array($data) ? $data : [];
    }

    private function validWebhook(Request $request): bool
    {
        $secret = config('multidomain-ghost.webhook_secret')
            ?: config('services.ghost.webhook_secret');
        if (empty($secret)) {
            return (bool) config('multidomain-ghost.allow_unsigned_webhooks', false);
        }

        $signature = (string) $request->header('X-Ghost-Signature', '');
        if (! preg_match('/^sha256=([a-f0-9]{64}),\s*t=(\d+)$/i', $signature, $matches)) {
            return false;
        }

        $timestamp = (int) $matches[2];
        $tolerance = max(0, (int) config('multidomain-ghost.webhook_tolerance', 300));
        $timestampSeconds = $timestamp > 10_000_000_000 ? intdiv($timestamp, 1000) : $timestamp;

        if ($tolerance > 0 && abs(now()->getTimestamp() - $timestampSeconds) > $tolerance) {
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            $request->getContent().$matches[2],
            (string) $secret,
        );

        return hash_equals(strtolower($matches[1]), $expected);
    }
}
