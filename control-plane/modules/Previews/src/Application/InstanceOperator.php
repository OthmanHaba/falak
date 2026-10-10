<?php

namespace Falak\Previews\Application;

use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Identity\Contracts\Role;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The organization that operates the instance and owns its preview domain (its DNS credential and edge server): the
 * one install.sh records (FALAK_DR_ORGANIZATION, like disaster recovery; its id only: a slug can be taken by
 * anyone who creates an organization first), else the only organization
 * of a single-organization install, else none. Its owners and admins edit Settings → Previews; everyone else only
 * sees whether previews are available.
 */
final class InstanceOperator
{
    public function __construct(
        private readonly OrganizationDirectory $organizations,
        private readonly OrganizationAccess $access,
    ) {}

    public function organizationId(): ?string
    {
        $configured = trim((string) config('previews.operator_organization'));
        $all = $this->organizations->all();

        if ($configured !== '') {
            if (preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', strtolower($configured)) !== 1) {
                return null;
            }

            foreach ($all as $organization) {
                if ($organization->id === strtolower($configured)) {
                    return $organization->id;
                }
            }

            return null;
        }

        // Not recorded: only a single-organization install has an obvious operator.
        return count($all) === 1 ? $all[0]->id : null;
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
