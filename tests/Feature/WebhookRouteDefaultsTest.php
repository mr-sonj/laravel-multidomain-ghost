<?php

namespace MrSonj\MultiDomainGhost\Tests\Feature;

use Illuminate\Routing\Route;
use MrSonj\MultiDomainGhost\Tests\TestCase;

/**
 * The webhook route is registered while the provider boots, so a config value set
 * in setUp() arrives too late. Clearing the block here proves what the route looks
 * like for an application whose published config never declared one - which is
 * every application, now that the shipped file no longer carries the block.
 */
class WebhookRouteDefaultsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $routes = (array) $app['config']->get('multidomain-ghost.routes');

        unset($routes['webhook']);

        $app['config']->set('multidomain-ghost.routes', $routes);
    }

    private function webhookRoute(): Route
    {
        return $this->app['router']->getRoutes()->getByName('multidomain-ghost.webhook');
    }

    public function test_the_route_is_registered_without_a_config_block(): void
    {
        $this->assertNotNull($this->webhookRoute());
        $this->assertSame('webhook/ghost/post', $this->webhookRoute()->uri());
    }

    public function test_the_route_is_rate_limited_by_default(): void
    {
        // Ghost retries a failed webhook, and the endpoint is unauthenticated until
        // the signature is checked. Losing the throttle turns it into an open door.
        $this->assertContains('throttle:500,1', $this->webhookRoute()->gatherMiddleware());
    }
}
