<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Facades;

use Illuminate\Support\Facades\Facade;
use MrSonj\MultiDomainGhost\Client\GhostClient;

/**
 * @method static array slugs()
 * @method static ?array pages()
 * @method static ?array list(?string $filter = null, ?string $fields = null, int $page = 1, int|string $limit = 'all', bool $modContent = true, ?string $include = 'tags')
 * @method static ?array content(string $canonicalUrl)
 * @method static array modContent(array $content)
 * @method static ?array findPrimaryTag(array $content)
 * @method static string|false urlToPath(string $url)
 * @method static array mod_content(array $content) @deprecated use modContent()
 * @method static ?array find_primary_tag(array $content) @deprecated use findPrimaryTag()
 * @method static string|false url_to_path(string $url) @deprecated use urlToPath()
 *
 * @see GhostClient
 */
class Ghost extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GhostClient::class;
    }
}
