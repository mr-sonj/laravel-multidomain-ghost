<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Console\Commands;

use Illuminate\Console\Command;
use MrSonj\MultiDomainGhost\Services\DomainResolver;

class DomainCurrentCommand extends Command
{
    protected $signature = 'domain';

    protected $description = 'Display the active application domain';

    public function handle(DomainResolver $resolver): int
    {
        $this->line($resolver->resolve());

        return self::SUCCESS;
    }
}
