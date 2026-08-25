<?php

namespace MrSonj\MultiDomainGhost\Tests\Feature;

use MrSonj\MultiDomainGhost\Tests\TestCase;

class DomainOptimizeCommandTest extends TestCase
{
    public function test_it_plans_a_cache_build_for_every_registered_domain(): void
    {
        $this->app['config']->set('domain.domains', [
            'a.com' => 'a.com',
            'b.com' => 'b.com',
        ]);

        $this->artisan('domain:optimize --pretend')
            ->expectsOutputToContain('config:cache --domain=a.com')
            ->expectsOutputToContain('route:cache --domain=a.com')
            ->expectsOutputToContain('config:cache --domain=b.com')
            ->expectsOutputToContain('route:cache --domain=b.com')
            ->assertExitCode(0);
    }

    public function test_it_reads_domains_from_the_package_config_when_present(): void
    {
        $this->app['config']->set('domain.domains', []);
        $this->app['config']->set('multidomain-ghost.domains', ['only.com']);

        $this->artisan('domain:optimize --pretend')
            ->expectsOutputToContain('config:cache --domain=only.com')
            ->assertExitCode(0);
    }

    public function test_clear_mode_plans_a_cache_clear_instead(): void
    {
        $this->app['config']->set('domain.domains', ['a.com' => 'a.com']);

        $this->artisan('domain:optimize --clear --pretend')
            ->expectsOutputToContain('optimize:clear --domain=a.com')
            ->assertExitCode(0);
    }

    public function test_it_can_be_limited_to_a_single_domain(): void
    {
        $this->app['config']->set('domain.domains', [
            'a.com' => 'a.com',
            'b.com' => 'b.com',
        ]);

        $this->artisan('domain:optimize --pretend --only=b.com')
            ->doesntExpectOutputToContain('--domain=a.com')
            ->expectsOutputToContain('config:cache --domain=b.com')
            ->assertExitCode(0);
    }

    public function test_it_fails_when_no_domains_are_registered(): void
    {
        $this->app['config']->set('domain.domains', []);
        $this->app['config']->set('multidomain-ghost.domains', []);

        $this->artisan('domain:optimize')->assertExitCode(1);
    }
}
