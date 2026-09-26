<?php

namespace Kiln\Identity\Application\Actions;

use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Converges the permission tables with the PermissionRegistry: creates missing permissions,
 * removes stale ones and gives each global role exactly its registered permissions.
 */
final class SyncPermissions
{
    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * @return array{permissions: int, roles: int}
     */
    public function __invoke(): array
    {
        $guard = 'web';
        $previousTeam = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId(null);

        try {
            $definitions = $this->registry->all();

            foreach ($definitions as $definition) {
                Permission::findOrCreate($definition->name, $guard);
            }

            Permission::query()
                ->where('guard_name', $guard)
                ->whereNotIn('name', array_keys($definitions))
                ->delete();

            foreach (RoleEnum::cases() as $roleEnum) {
                $role = Role::query()
                    ->whereNull('organization_id')
                    ->where('name', $roleEnum->value)
                    ->where('guard_name', $guard)
                    ->first() ?? Role::create(['name' => $roleEnum->value, 'guard_name' => $guard, 'organization_id' => null]);

                $granted = array_keys(array_filter(
                    $definitions,
                    fn ($definition) => in_array($roleEnum, $definition->roles, true),
                ));

                $role->syncPermissions($granted);
            }

            $this->registrar->forgetCachedPermissions();

            return ['permissions' => count($definitions), 'roles' => count(RoleEnum::cases())];
        } finally {
            $this->registrar->setPermissionsTeamId($previousTeam);
        }
    }
}
