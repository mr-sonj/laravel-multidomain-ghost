<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Support;

use Stringable;

/**
 * One hostname, and the forms this package keeps deriving from it.
 *
 * Those derivations were spread across DomainName, DomainResolver and
 * GhostClient - the static passthroughs on the latter two exist only because a
 * bare `string $domain` was being handed around. Passing a Domain instead is
 * what makes them unnecessary.
 *
 * Not usable during bootstrap: tag() reads configuration. Anything that runs
 * before the config repository exists stays on DomainName.
 */
final class Domain implements Stringable
{
    private function __construct(private readonly string $host) {}

    public static function make(string $domain): self
    {
        return new self(DomainName::normalize($domain));
    }

    /**
     * The bare, lowercase hostname - empty when the input was not a usable host.
     */
    public function host(): string
    {
        return $this->host;
    }

    /**
     * Directory-safe form, used for view paths, storage and config overrides.
     * `10mailbox.com` becomes `10mailbox_com`.
     */
    public function key(): string
    {
        return DomainName::dirKey($this->host);
    }

    /**
     * The Ghost tag slug: dots become hyphens, behind the configured prefix.
     * `10mailbox.com` becomes `hash-10mailbox-com`.
     *
     * Read at call time rather than cached, so a test or a runtime config change
     * cannot leave a stale prefix behind.
     */
    public function tag(): string
    {
        $prefix = (string) config('multidomain-ghost.domain_tag_prefix', 'hash-');

        return $prefix.str_replace('.', '-', $this->host);
    }

    /**
     * The Ghost API filter selecting this domain's content.
     */
    public function filter(): string
    {
        return 'tag:'.$this->tag();
    }

    public function __toString(): string
    {
        return $this->host;
    }
}
