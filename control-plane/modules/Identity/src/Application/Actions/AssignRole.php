<?php

namespace Kiln\Identity\Application\Actions;

use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\User;
use Kiln\Identity\Infrastructure\SpatieOrganizationAccess;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sets the member's single role within an organization.
 */
final class AssignRole
{
    public function __construct(
        private readonly PermissionRegistrar $registrar,
        private readonly OrganizationAccess $access,
    ) {}

    public function __invoke(User $user, string $organizationId, ?Role $role): void
    {
        $previous = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($organizationId);

        try {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->syncRoles($role ? [$role->value] : []);
        } finally {
            $this->registrar->setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }

        if ($this->access instanceof SpatieOrganizationAccess) {
            $this->access->flush();
        }
    }
}
