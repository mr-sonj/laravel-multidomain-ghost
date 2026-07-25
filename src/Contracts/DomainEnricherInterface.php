<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Contracts;

interface DomainEnricherInterface
{
    /**
     * Enrich Ghost content with domain-specific data.
     *
     * @param  array  $content  Content from Ghost CMS
     * @param  string  $canonicalUrl  Canonical URL of the current page
     * @return array Enriched content
     */
    public function enrich(array $content, string $canonicalUrl): array;
}
