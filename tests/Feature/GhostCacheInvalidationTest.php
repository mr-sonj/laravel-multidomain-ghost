<?php

namespace MrSonj\MultiDomainGhost\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use MrSonj\MultiDomainGhost\Client\GhostClient;
use MrSonj\MultiDomainGhost\Services\DomainResolver;
use MrSonj\MultiDomainGhost\Services\GhostCacheManager;
use MrSonj\MultiDomainGhost\Services\GhostContentService;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class GhostCacheInvalidationTest extends TestCase
{
    /** Prefix b.com is actually served under - not the {dirKey}_cache convention. */
    private const SERVING_PREFIX = 'shared_cache';

    /** Prefix active on the host Ghost posts the webhook to. */
    private const WEBHOOK_PREFIX = 'webhook_host_cache';

    /**
     * The cache store has to be settled before providers boot, exactly as it is in
     * a real application: the package derives its own store from `cache.default`
     * once, at boot.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'database');
        $app['config']->set('cache.stores.database.connection', 'testing');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('multidomain-ghost.url', 'https://cms.example.com');
        $this->app['config']->set('multidomain-ghost.content_key', 'content-key');

        Schema::create('cache', function ($table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
    }

    /**
     * Each Laravel request resolves the cache store once and memoizes it with
     * whatever cache.prefix the active domain configured. Route middleware such
     * as throttle resolves it before any controller runs, so by the time the
     * webhook handler executes the prefix is already locked in.
     */
    private function enterRequestForDomain(string $cachePrefix): void
    {
        $this->app['config']->set('cache.prefix', $cachePrefix);
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        Cache::clearResolvedInstances();

        Cache::get('throttle:probe');
    }

    private function serviceFor(string $domain): GhostContentService
    {
        return new GhostContentService(
            new GhostClient($domain, false),
            (new DomainResolver)->setDomain($domain),
        );
    }

    private function upstreamCallCount(): int
    {
        $count = 0;
        Http::recorded(function () use (&$count) {
            $count++;

            return false;
        });

        return $count;
    }

    public function test_webhook_purges_the_slug_cache_of_a_domain_it_is_not_serving(): void
    {
        Http::fake(['*' => Http::response(['posts' => []])]);

        $this->enterRequestForDomain(self::SERVING_PREFIX);
        $this->serviceFor('b.com')->slugs();
        $afterPrime = $this->upstreamCallCount();

        $this->enterRequestForDomain(self::SERVING_PREFIX);
        $this->serviceFor('b.com')->slugs();
        $this->assertSame($afterPrime, $this->upstreamCallCount(), 'slugs should be cached');

        $this->enterRequestForDomain(self::WEBHOOK_PREFIX);
        (new GhostCacheManager($this->serviceFor('a.com')))->purgeSlugsCache('b.com');

        $this->enterRequestForDomain(self::SERVING_PREFIX);
        $this->serviceFor('b.com')->slugs();

        $this->assertGreaterThan(
            $afterPrime,
            $this->upstreamCallCount(),
            'purgeSlugsCache() did not clear the slug cache of the target domain',
        );
    }

    public function test_webhook_purges_the_post_cache_of_a_domain_it_is_not_serving(): void
    {
        $url = 'https://b.com/hello';
        Http::fake(['*' => Http::response(['posts' => [['canonical_url' => $url, 'title' => 'Hello']]])]);

        $this->enterRequestForDomain(self::SERVING_PREFIX);
        $this->serviceFor('b.com')->getPost($url);
        $afterPrime = $this->upstreamCallCount();

        $this->enterRequestForDomain(self::SERVING_PREFIX);
        $this->serviceFor('b.com')->getPost($url);
        $this->assertSame($afterPrime, $this->upstreamCallCount(), 'post should be cached');

        $this->enterRequestForDomain(self::WEBHOOK_PREFIX);
        (new GhostCacheManager($this->serviceFor('a.com')))->purgePostCache($url);

        $this->enterRequestForDomain(self::SERVING_PREFIX);
        $this->serviceFor('b.com')->getPost($url);

        $this->assertGreaterThan(
            $afterPrime,
            $this->upstreamCallCount(),
            'purgePostCache() did not clear the post cache of the target domain',
        );
    }

    public function test_webhook_rotates_the_blog_generation_of_a_domain_it_is_not_serving(): void
    {
        Http::fake(['*' => Http::response(['posts' => [['canonical_url' => 'https://b.com/p', 'title' => 'P']]])]);

        $this->enterRequestForDomain(self::SERVING_PREFIX);
        $this->serviceFor('b.com')->dataBlog(1, 15);
        $afterPrime = $this->upstreamCallCount();

        $this->enterRequestForDomain(self::SERVING_PREFIX);
        $this->serviceFor('b.com')->dataBlog(1, 15);
        $this->assertSame($afterPrime, $this->upstreamCallCount(), 'blog listing should be cached');

        $this->enterRequestForDomain(self::WEBHOOK_PREFIX);
        (new GhostCacheManager($this->serviceFor('a.com')))->purgeDataBlogCache('b.com');

        $this->enterRequestForDomain(self::SERVING_PREFIX);
        $this->serviceFor('b.com')->dataBlog(1, 15);

        $this->assertGreaterThan(
            $afterPrime,
            $this->upstreamCallCount(),
            'purgeDataBlogCache() did not rotate the blog generation of the target domain',
        );
    }
}
