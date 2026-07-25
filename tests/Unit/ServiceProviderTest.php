<?php

namespace MrSonj\MultiDomainGhost\Tests\Unit;

use MrSonj\MultiDomainGhost\Contracts\ContentTransformerInterface;
use MrSonj\MultiDomainGhost\Contracts\DomainEnricherInterface;
use MrSonj\MultiDomainGhost\Support\NullContentTransformer;
use MrSonj\MultiDomainGhost\Support\NullEnricher;
use MrSonj\MultiDomainGhost\Tests\TestCase;

class TestCustomEnricher implements DomainEnricherInterface
{
    public function enrich(array $content, string $canonicalUrl): array
    {
        return array_merge($content, ['enriched' => true]);
    }
}

class TestCustomTransformer implements ContentTransformerInterface
{
    public function transform(array $content, string $domain): array
    {
        return array_merge($content, ['transformed' => true]);
    }
}

class ServiceProviderTest extends TestCase
{
    public function test_resolves_null_enricher_and_null_transformer_by_default(): void
    {
        $enricher = $this->app->make(DomainEnricherInterface::class);
        $transformer = $this->app->make(ContentTransformerInterface::class);

        $this->assertInstanceOf(NullEnricher::class, $enricher);
        $this->assertInstanceOf(NullContentTransformer::class, $transformer);
    }

    public function test_resolves_enricher_and_transformer_from_config(): void
    {
        $this->app['config']->set('multidomain-ghost.enrichers', [
            'example.com' => TestCustomEnricher::class,
        ]);
        $this->app['config']->set('multidomain-ghost.transformer', TestCustomTransformer::class);

        $this->get('http://example.com/test');

        $enricher = $this->app->make(DomainEnricherInterface::class);
        $transformer = $this->app->make(ContentTransformerInterface::class);

        $this->assertInstanceOf(TestCustomEnricher::class, $enricher);
        $this->assertInstanceOf(TestCustomTransformer::class, $transformer);
    }
}
