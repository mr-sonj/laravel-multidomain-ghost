<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Foundation\Console;

use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

class Application extends \Illuminate\Console\Application
{
    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();

        if (! $definition->hasOption('domain')) {
            $definition->addOption(new InputOption(
                '--domain',
                null,
                InputOption::VALUE_OPTIONAL,
                'The domain the command should run under.',
            ));
        }

        return $definition;
    }
}
