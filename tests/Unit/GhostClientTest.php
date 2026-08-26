<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use Illuminate\Support\Facades\Http;
use MrSonj\MultiDomainGhost\Client\GhostClient;
use MrSonj\MultiDomainGhost\Contracts\ContentTransformerInterface;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class GhostClientTest extends TestCase
{
    public function test_content_api_accepts_a_ghost_site_url(): void
    {
        $this->app['config']->set('multidomain-ghost.url', 'https://cms.example.com');
        $this->app['config']->set('multidomain-ghost.content_key', 'content-key');
        Http::fake([
            '*' => Http::response(['posts' => []]),
        ]);

        (new GhostClient('example.com', false))->list();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://cms.example.com/ghost/api/content/posts?filter=tag%3Ahash-example-com&limit=all&page=1&include=tags&key=content-key'
            && $request->hasHeader('Accept-Version', 'v6.0'));
    }

    public function test_content_api_normalizes_a_legacy_versioned_api_url(): void
    {
        $this->app['config']->set('multidomain-ghost.url', 'https://cms.example.com/ghost/api/v5');
        $this->app['config']->set('multidomain-ghost.content_key', 'content-key');
        Http::fake([
            '*' => Http::response(['posts' => []]),
        ]);

        (new GhostClient('example.com', false))->list();

        Http::assertSent(fn ($request): bool => str_starts_with(
            $request->url(),
            'https://cms.example.com/ghost/api/content/posts?',
        ));
    }

    public function test_admin_api_accepts_a_ghost_site_url(): void
    {
        $this->app['config']->set('multidomain-ghost.admin_url', 'https://cms.example.com');
        $this->app['config']->set('multidomain-ghost.admin_key', 'key:'.str_repeat('a', 64));
        Http::fake([
            '*' => Http::response(['posts' => []]),
        ]);

        (new GhostClient('example.com', true))->list();

        Http::assertSent(fn ($request): bool => str_starts_with(
            $request->url(),
            'https://cms.example.com/ghost/api/admin/posts?',
        ));
    }

    public function test_sitemap_links_include_posts(): void
    {
        $this->app['config']->set('multidomain-ghost.url', 'https://cms.example.com');
        $this->app['config']->set('multidomain-ghost.content_key', 'content-key');
        Http::fake([
            '*/posts*' => Http::response([
                'posts' => [
                    ['canonical_url' => 'https://example.com/blog/post'],
                    ['canonical_url' => 'https://example.com/shared'],
                ],
            ]),
        ]);

        $links = (new GhostClient('example.com', false))->slugs();

        $this->assertSame([
            'https://example.com/blog/post',
            'https://example.com/shared',
        ], array_column($links, 'canonical_url'));
        Http::assertSentCount(1);
    }

    public function test_content_fetches_from_the_posts_endpoint(): void
    {
        $this->app['config']->set('multidomain-ghost.url', 'https://cms.example.com');
        $this->app['config']->set('multidomain-ghost.content_key', 'content-key');
        Http::fake([
            '*/posts*' => Http::response([
                'posts' => [[
                    'title' => 'About',
                    'canonical_url' => 'https://example.com/about',
                ]],
            ]),
        ]);

        $content = (new GhostClient('example.com', false))->content('https://example.com/about');

        $this->assertSame('About', $content['title']);
        $this->assertSame('example.com', $content['domain']);
        Http::assertSentCount(1);
    }

    public function test_core_normalization_does_not_apply_frontend_or_brand_specific_mutations(): void
    {
        $content = (new GhostClient('example.com', false))->mod_content([
            'title' => 'Brand | Original title',
            'canonical_url' => 'https://example.com/blog/original-title',
            'html' => '<div class="kg-card kg-toggle-card"><a href="/path?ref=sonjj.com">Link</a></div>',
        ]);

        $this->assertSame('Brand | Original title', $content['title']);
        $this->assertSame(
            '<div class="kg-card kg-toggle-card"><a href="/path?ref=sonjj.com">Link</a></div>',
            $content['html'],
        );
        $this->assertSame('blog/original-title', $content['path']);
    }

    public function test_application_can_transform_normalized_content_through_the_contract(): void
    {
        $transformer = new class implements ContentTransformerInterface
        {
            public function transform(array $content, string $domain): array
            {
                $content['transformed_for'] = $domain;

                return $content;
            }
        };

        $content = (new GhostClient('example.com', false, $transformer))->mod_content([]);

        $this->assertSame('example.com', $content['transformed_for']);
    }

    public function test_schema_injected_through_the_primary_domain_tag_is_detected(): void
    {
        $content = (new GhostClient('example.com', false))->mod_content([
            'canonical_url' => 'https://example.com/a',
            'codeinjection_head' => '',
            'tags' => [[
                'slug' => 'hash-example-com',
                'codeinjection_head' => '<script type="application/ld+json">{"@type":"Article"}</script>',
            ]],
        ]);

        $this->assertTrue(
            $content['schema'],
            'JSON-LD carried by the primary domain tag must set the schema flag, otherwise the view emits a second one',
        );
    }

    public function test_schema_stays_false_when_no_json_ld_is_present(): void
    {
        $content = (new GhostClient('example.com', false))->mod_content([
            'canonical_url' => 'https://example.com/a',
            'codeinjection_head' => '<meta name="x" content="y">',
            'tags' => [['slug' => 'hash-example-com', 'codeinjection_head' => '<style>a{}</style>']],
        ]);

        $this->assertFalse($content['schema']);
    }

    public function test_camel_case_api_mirrors_the_legacy_snake_case_methods(): void
    {
        $client = new GhostClient('example.com', false);
        $raw = [
            'canonical_url' => 'https://example.com/a/b',
            'tags' => [['slug' => 'hash-example-com', 'name' => '#example.com']],
        ];

        $this->assertSame($client->mod_content($raw), $client->modContent($raw));
        $this->assertSame($client->find_primary_tag($raw), $client->findPrimaryTag($raw));
        $this->assertSame('a/b', $client->urlToPath('https://example.com/a/b'));
    }
}
