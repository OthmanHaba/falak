<?php

namespace Kiln\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
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
