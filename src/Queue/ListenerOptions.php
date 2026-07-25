<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Queue;

use Illuminate\Queue\ListenerOptions as LaravelListenerOptions;

class ListenerOptions extends LaravelListenerOptions
{
    public function __construct(
        $name = 'default',
        $environment = null,
        $backoff = 0,
        $memory = 128,
        $timeout = 60,
        $sleep = 3,
        $maxTries = 1,
        $force = false,
        $rest = 0,
        public ?string $domain = null,
    ) {
        parent::__construct(
            $name,
            $environment,
            $backoff,
            $memory,
            $timeout,
            $sleep,
            $maxTries,
            $force,
            $rest,
        );
    }
}
