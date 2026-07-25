<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Queue;

use Illuminate\Queue\Listener as LaravelListener;
use Illuminate\Queue\ListenerOptions as LaravelListenerOptions;
use Symfony\Component\Process\Process;

class Listener extends LaravelListener
{
    public function makeProcess($connection, $queue, LaravelListenerOptions $options): Process
    {
        $command = $this->createCommand($connection, $queue, $options);

        if ($options instanceof ListenerOptions && filled($options->domain)) {
            $command[] = "--domain={$options->domain}";
        }

        if (isset($options->environment)) {
            $command = $this->addEnvironment($command, $options);
        }

        return new Process(
            $command,
            $this->commandPath,
            null,
            null,
            $options->timeout,
        );
    }
}
