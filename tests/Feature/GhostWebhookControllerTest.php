<?php

namespace MrSonj\MultiDomainGhost\Tests\Feature;

use MrSonj\MultiDomainGhost\Contracts\DomainEnricherInterface;
use MrSonj\MultiDomainGhost\Http\Controllers\GhostWebhookController;
use MrSonj\MultiDomainGhost\Tests\TestCase;
use RuntimeException;

class GhostWebhookControllerTest extends TestCase
{
    public function test_a_webhook_never_builds_the_domain_enricher(): void
    {
        // The enricher for a real domain can reach a third-party API. A webhook
        // has no use for it, so resolving one is a bug, not a slow path.
        $this->app->bind(DomainEnricherInterface::class, function (): never {
            throw new RuntimeException('The enricher must not be built for a webhook.');
        });

        $this->app['config']->set('multidomain-ghost.allow_unsigned_webhooks', true);
        $this->app['config']->set('domains.example_com', []);

        $response = $this->postJson('/webhook/ghost/post', [
            'name' => 'post.published',
            'post' => ['current' => ['canonical_url' => 'https://example.com/a']],
        ]);

        $response->assertOk();
        $response->assertJsonPath('content_type', 'post');
    }

    public function test_the_route_resolves_the_dedicated_webhook_controller(): void
    {
        $action = $this->app['router']->getRoutes()
            ->getByName('multidomain-ghost.webhook')
            ->getActionName();

        $this->assertStringStartsWith(GhostWebhookController::class, $action);
    }

    public function test_it_rejects_an_unsigned_webhook_when_a_secret_is_configured(): void
    {
        $this->app['config']->set('multidomain-ghost.webhook_secret', 'secret123');

        $this->postJson('/webhook/ghost/post', [
            'post' => ['current' => ['canonical_url' => 'https://example.com/a']],
        ])->assertForbidden();
    }

    public function test_it_ignores_a_domain_that_is_not_registered(): void
    {
        $this->app['config']->set('multidomain-ghost.allow_unsigned_webhooks', true);
        $this->app['config']->set('domains.example_com', []);

        $this->postJson('/webhook/ghost/post', [
            'post' => ['current' => ['canonical_url' => 'https://stranger.com/a']],
        ])->assertJsonPath('message', 'Ignored. Domain not registered in this application.');
    }
}
