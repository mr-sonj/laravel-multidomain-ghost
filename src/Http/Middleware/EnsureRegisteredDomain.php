<?php

declare(strict_types=1);

namespace MrSonj\MultiDomainGhost\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MrSonj\MultiDomainGhost\Support\DomainName;
use MrSonj\MultiDomainGhost\Support\DomainRegistry;
use Symfony\Component\HttpFoundation\Response;

class EnsureRegisteredDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $domain = DomainName::normalize($request->getHost());
        $isRegistered = DomainRegistry::contains($domain)
            || (str_starts_with($domain, 'www.') && DomainRegistry::contains(substr($domain, 4)));

        abort_unless($isRegistered, 404);

        return $next($request);
    }
}
