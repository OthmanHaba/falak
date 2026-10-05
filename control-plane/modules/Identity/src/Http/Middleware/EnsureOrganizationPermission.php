<?php

namespace Falak\Identity\Http\Middleware;

use Closure;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `org.can:<permission>` — requires the permission in the current organization.
 */
final class EnsureOrganizationPermission
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $this->access->authorize($request->user(), $this->organization->requireId(), $permission);

        return $next($request);
    }
}
