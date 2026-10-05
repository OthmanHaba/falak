<?php

namespace Falak\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Falak\Identity\Contracts\CurrentOrganization;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `org` — sends users without any organization to create one (web) or 403s (API).
 */
final class EnsureCurrentOrganization
{
    public function __construct(private readonly CurrentOrganization $organization) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->organization->id() === null) {
            if ($request->expectsJson()) {
                abort(403, 'No organization selected.');
            }

            return redirect()->route('organizations.create');
        }

        return $next($request);
    }
}
