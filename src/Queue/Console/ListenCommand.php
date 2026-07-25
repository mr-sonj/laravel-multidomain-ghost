<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Queue\Console;

use Illuminate\Queue\Console\ListenCommand as LaravelListenCommand;
use MrSonj\MultiDomainGhost\Queue\ListenerOptions;

class ListenCommand extends LaravelListenCommand
{
    protected function gatherOptions()
    {
        $backoff = $this->hasOption('backoff')
            ? $this->option('backoff')
            : $this->option('delay');

        return new ListenerOptions(
            name: $this->option('name'),
            environment: $this->option('env'),
            backoff: $backoff,
            memory: $this->option('memory'),
            timeout: $this->option('timeout'),
            sleep: $this->option('sleep'),
            maxTries: $this->option('tries'),
            force: $this->option('force'),
            rest: $this->option('rest'),
            domain: $this->option('domain'),
        );
    }
}
