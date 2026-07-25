<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Support;

use MrSonj\MultiDomainGhost\Contracts\ContentTransformerInterface;

class NullContentTransformer implements ContentTransformerInterface
{
    public function transform(array $content, string $domain): array
    {
        return $content;
    }
}
