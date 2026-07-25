<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Queue;

use Illuminate\Queue\Console\ListenCommand as LaravelListenCommand;
use Illuminate\Queue\QueueServiceProvider as LaravelQueueServiceProvider;
use MrSonj\MultiDomainGhost\Queue\Console\ListenCommand;

class QueueServiceProvider extends LaravelQueueServiceProvider
{
    protected function registerListener(): void
    {
        $this->app->singleton('queue.listener', fn () => new Listener($this->app->basePath()));
    }

    public function register(): void
    {
        parent::register();

        $this->app->extend(
            LaravelListenCommand::class,
            fn ($command, $app) => new ListenCommand($app['queue.listener']),
        );
    }
}
