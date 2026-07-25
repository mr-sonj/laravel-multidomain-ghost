<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Contracts;

interface ContentTransformerInterface
{
    /**
     * Apply application-specific transformations to normalized Ghost content.
     */
    public function transform(array $content, string $domain): array;
}
