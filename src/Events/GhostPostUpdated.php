<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Events;

class GhostPostUpdated
{
    public function __construct(
        public readonly string $webhookName,
        public readonly string $contentType,
        public readonly array $cacheCleared,
        public readonly array $domains,
    ) {}
}
