<?php

namespace Kiln\Identity\Infrastructure;

use InvalidArgumentException;
use Kiln\Identity\Contracts\Permission;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;

final class InMemoryPermissionRegistry implements PermissionRegistry
{
    /** @var array<string, Permission> */
    private array $permissions = [];

    public function register(string $name, array $roles, string $description = '', string $group = ''): void
    {
        if (! preg_match('/^[a-z][a-z0-9_-]*(\.[a-z0-9_-]+)+$/', $name)) {
            throw new InvalidArgumentException("Invalid permission name [{$name}]; expected dotted lowercase like \"servers.create\".");
        }

        // The owner always holds every permission.
        $roles = array_values(array_unique([Role::Owner, ...$roles], SORT_REGULAR));

        $this->permissions[$name] = new Permission($name, $roles, $description, $group ?: explode('.', $name)[0]);
    }

    public function all(): array
    {
        ksort($this->permissions);

        return $this->permissions;
    }

    public function has(string $name): bool
    {
        return isset($this->permissions[$name]);
    }
}
