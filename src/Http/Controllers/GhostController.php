<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use MrSonj\MultiDomainGhost\Contracts\DomainEnricherInterface;
use MrSonj\MultiDomainGhost\Http\Middleware\EnsureRegisteredDomain;
use MrSonj\MultiDomainGhost\Services\GhostCacheManager;
use MrSonj\MultiDomainGhost\Services\GhostContentService;
use MrSonj\MultiDomainGhost\Support\Domain;
use MrSonj\MultiDomainGhost\Support\DomainAssets;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class GhostController extends Controller
{
    private string $domain;

    public function __construct(
        private DomainEnricherInterface $enricher,
        private GhostContentService $ghostContentService
    ) {
        $this->middleware(EnsureRegisteredDomain::class)->except('postWebhook');
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
        $image = $this->seoValue($content, 'feature_image')
            ?? $this->defaultSeoImage((string) ($content['domain'] ?? $this->domain));
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
                'is_part_of' => 'https://'.($content['domain'] ?? $this->domain).'/#website',
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
        $viewPath = $this->viewPath($request, 'page');
        $content = $this->content($request);
        $seo = $this->seoData($content);

        return view($viewPath)->with([
            'content' => $content,
            'seo' => $seo,
        ]);
    }

    public function blog(Request $request): View
    {
        $page = $this->requestedPage($request);
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
        $viewPath = $this->viewPath($request, 'blog');

        return view($viewPath)->with([
            'content' => $content,
            'seo' => $this->seoData($content),
            'dataBlog' => $dataBlog,
            'page' => $page,
        ]);
    }

    /**
     * The domain's robots policy.
     *
     * A resources/views/{domain_key}/robots.txt replaces this method's output
     * wholesale rather than being appended to it: appending would keep imposing
     * the package's own lines on every domain, which is the coupling the file is
     * there to break. The file's author owns the Sitemap: line too.
     */
    public function robots(): Response
    {
        $file = DomainAssets::contents($this->domain, 'robots.txt');

        if ($file !== null) {
            return response($file)->header('Content-Type', 'text/plain;charset=UTF-8');
        }

        $lines = ['User-agent: *'];

        foreach ((array) config('multidomain-ghost.robots.disallow', ['/cdn-cgi/']) as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        $sitemap = $this->expandDomainPlaceholders(
            (string) config('multidomain-ghost.robots.sitemap', 'https://{domain}/sitemap.xml'),
            $this->domain,
        );

        if ($sitemap !== '') {
            $lines[] = 'Sitemap: '.$sitemap;
        }

        $contentSignal = config('multidomain-ghost.robots.content_signal')
            ?: config('services.robots.content_signal');
        if (! empty($contentSignal)) {
            $lines[] = 'Content-Signal: '.$contentSignal;
        }

        return response(trim(implode("\n", $lines)))
            ->header('Content-Type', 'text/plain;charset=UTF-8');
    }

    /**
     * The domain's own ads.txt.
     *
     * 404 rather than an empty 200 because an empty ads.txt is itself a claim -
     * that the domain authorises no sellers - which a domain without the file is
     * not making. The registrar leaves the route unregistered in that case; the
     * guard in domainFile() covers a route the application declared for itself.
     */
    public function ads(): Response
    {
        return $this->domainFile('ads.txt');
    }

    /**
     * The domain's own llms.txt: the index an AI crawler reads in place of
     * discovering the site page by page.
     *
     * File-backed only, on the same reasoning as ads.txt. The package could
     * assemble one from Ghost's posts, but llms.txt is an editorial statement of
     * what a site wants read and in what order, not an inventory - a generated
     * one would put words in the publisher's mouth.
     */
    public function llms(): Response
    {
        return $this->domainFile('llms.txt');
    }

    /**
     * The domain's own llms-full.txt: the expanded companion to llms.txt,
     * carrying the content itself rather than links to it.
     */
    public function llmsFull(): Response
    {
        return $this->domainFile('llms-full.txt');
    }

    /**
     * One of the text files under resources/views/{domain_key}/, served verbatim.
     *
     * Verbatim because each of these is somebody else's format - ads.txt is IAB's,
     * llms.txt is a markdown convention - and rewriting a format you do not own is
     * a risk. text/plain for all of them: llms.txt is markdown by content, but it
     * is fetched as a file, not rendered, and the neighbouring files are plain.
     */
    private function domainFile(string $file): Response
    {
        $contents = DomainAssets::contents($this->domain, $file);

        if ($contents === null) {
            abort(404);
        }

        return response($contents)->header('Content-Type', 'text/plain;charset=UTF-8');
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
        $page = $this->requestedPage($request);
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

        $selfUrl = $request->url();
        $language = str_replace('_', '-', (string) config('app.locale', 'en'));

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            ."\n".'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">'
            ."\n    <channel>"
            ."\n        <title>".$this->xml($channelTitle).'</title>'
            ."\n        <link>".$this->xml($siteUrl).'</link>'
            ."\n        <description>".$this->xml($channelTitle.' feed').'</description>'
            ."\n        <language>".$this->xml($language).'</language>'
            ."\n        <lastBuildDate>".$this->xml(now()->toRfc2822String()).'</lastBuildDate>'
            ."\n        <atom:link href=\"".$this->xml($selfUrl).'" rel="self" type="application/rss+xml"/>'
            .($items !== '' ? "\n{$items}" : '')
            ."\n    </channel>"
            ."\n</rss>\n";

        return response($xml, 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
        ]);
    }

    /**
     * @deprecated Route webhooks at GhostWebhookController instead. Kept so an
     *             application that points its own route here keeps working.
     */
    public function postWebhook(Request $request, GhostCacheManager $cacheManager): JsonResponse
    {
        return app(GhostWebhookController::class)($request, $cacheManager);
    }

    /**
     * The image used when a post carries no feature image of its own.
     *
     * Kept as a template so applications are not tied to this package's asset
     * layout: {domain} is the hostname, {domain_key} its directory-safe form.
     */
    private function defaultSeoImage(string $domain): string
    {
        return $this->expandDomainPlaceholders(
            (string) config(
                'multidomain-ghost.seo.default_image',
                'https://{domain}/img/{domain_key}/apple-touch-icon.png',
            ),
            $domain,
        );
    }

    private function expandDomainPlaceholders(string $template, string $domain): string
    {
        return strtr($template, [
            '{domain}' => $domain,
            '{domain_key}' => Domain::make($domain)->key(),
        ]);
    }

    /**
     * The view to render, and a working one when the declared view is missing.
     *
     * A route's `viewPath` default names a file the application owns, so it can
     * name one that was never created, or one deleted since. Rendering it anyway
     * throws, and the request that finds out is a public one - the first time
     * Ghost returns content for that route, which is the worst possible moment.
     *
     * The package's own view carries the full document and every SEO tag, so the
     * fallback is an unstyled page rather than a broken one, and the warning is
     * what makes the mistake findable. `domain:list` reports the same mistake
     * before a deploy; this is the net under it.
     */
    private function viewPath(Request $request, string $key): string
    {
        $packaged = "multidomain-ghost::{$key}";
        $configured = trim((string) config("multidomain-ghost.views.{$key}", $packaged)) ?: $packaged;
        $requested = $request->route('viewPath');
        $declared = is_string($requested) && trim($requested) !== ''
            ? trim($requested)
            : $configured;

        $factory = view();

        if ($factory->exists($declared)) {
            return $declared;
        }

        $fallback = $declared !== $configured && $factory->exists($configured)
            ? $configured
            : $packaged;

        Log::warning("multidomain-ghost: view [{$declared}] does not exist, rendering [{$fallback}] instead.", [
            'domain' => $this->domain,
            'route' => $request->route()?->getName(),
        ]);

        return $fallback;
    }

    /**
     * Read the requested listing page.
     *
     * Without an upper bound every distinct ?page= value mints a new cache entry
     * and a new Ghost request, so an unbounded crawl becomes an amplification
     * vector against the CMS. Anything past `max_blog_page` is not a page of this
     * blog, so it 404s: serving the last page under a different ?page= value would
     * publish the same listing under unlimited URLs.
     *
     * A junk or negative value is treated as the first page instead - it maps onto
     * an entry that already exists, so it carries no amplification.
     */
    private function requestedPage(Request $request): int
    {
        $page = max(1, (int) $request->integer('page', 1));
        $max = max(1, (int) config('multidomain-ghost.max_blog_page', 200));

        if ($page > $max) {
            abort(404);
        }

        return $page;
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
}
