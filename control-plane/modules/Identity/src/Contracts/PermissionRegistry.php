<?php

namespace Falak\Identity\Contracts;

/**
 * Modules declare their permissions (e.g. "servers.create") and the default roles holding them
 * in their service provider's boot(). Identity syncs the registry into the permission tables after
 * every migration run and via `php artisan identity:permissions:sync`.
 *
 * Permission names double as Sanctum token abilities.
 */
interface PermissionRegistry
{
    /**
     * @param  list<Role>  $roles
     */
    public function register(string $name, array $roles, string $description = '', string $group = ''): void;

    /**
     * @return array<string, Permission>
     */
    public function all(): array;

    public function has(string $name): bool;
}
