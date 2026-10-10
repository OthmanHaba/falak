<?php

namespace Falak\Previews\Application;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Identity\Contracts\Role;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The organization that operates the instance and owns its preview domain (its DNS credential and edge server):
 * config('previews.operator_organization'), else the oldest organization (the one `falak-ctl admin create` made
 * first). Its owners and admins edit Settings → Previews; everyone else only sees whether previews are available.
 */
final class InstanceOperator
{
    public function __construct(
        private readonly OrganizationDirectory $organizations,
        private readonly OrganizationAccess $access,
    ) {}

    public function organizationId(): ?string
    {
        $configured = config('previews.operator_organization');

        if (is_string($configured) && $configured !== '') {
            return $this->organizations->find(strtolower($configured))?->id;
        }

        // Oldest first.
        return $this->organizations->all()[0]->id ?? null;
    }

    public function isAdmin(?Authenticatable $user): bool
    {
        $organizationId = $this->organizationId();

        if ($user === null || $organizationId === null) {
            return false;
        }

        $role = $this->access->roleOf((string) $user->getAuthIdentifier(), $organizationId);

        return $role === Role::Owner || $role === Role::Admin;
    }
}
