<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use MrSonj\MultiDomainGhost\Events\GhostPostUpdated;
use MrSonj\MultiDomainGhost\Services\GhostCacheManager;
use MrSonj\MultiDomainGhost\Support\DomainName;
use MrSonj\MultiDomainGhost\Support\DomainRegistry;

/**
 * Cache invalidation for Ghost webhooks.
 *
 * Separate from GhostController on purpose: that controller injects the domain's
 * enricher, which for a real application can reach a third-party API. A webhook
 * purges cache keys and touches nothing an enricher provides.
 */
class GhostWebhookController extends Controller
{
    public function __invoke(Request $request, GhostCacheManager $cacheManager): JsonResponse
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

        foreach ($posts->pluck('canonical_url')->filter()->unique() as $canonicalUrl) {
            $host = parse_url($canonicalUrl, PHP_URL_HOST);
            $domain = DomainName::normalize((string) $host);

            if ($domain === '' || ! DomainRegistry::contains($domain)) {
                continue;
            }

            $variants = $cacheManager->purgePostCache($canonicalUrl);
            array_push($cacheCleared, ...$variants);
            $domainsToClear[] = $domain;
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
            }
        }

        Log::info('Ghost webhook invalidated cached content.', [
            'webhook' => $nameWebhook,
            'domains' => $domainsToClear,
            'cache_cleared' => $cacheCleared,
        ]);

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
