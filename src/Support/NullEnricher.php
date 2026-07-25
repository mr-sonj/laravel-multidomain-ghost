<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Support;

use MrSonj\MultiDomainGhost\Contracts\DomainEnricherInterface;

class NullEnricher implements DomainEnricherInterface
{
    public function enrich(array $content, string $canonicalUrl): array
    {
        return $content;
    }
}
