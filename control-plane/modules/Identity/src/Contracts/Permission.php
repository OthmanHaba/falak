<?php

namespace Kiln\Identity\Contracts;

/**
 * A registered permission. Modules register their own through {@see PermissionRegistry}.
 */
final readonly class Permission
{
    /**
     * @param  list<Role>  $roles  roles granted this permission by default
     */
    public function __construct(
        public string $name,
        public array $roles,
        public string $description = '',
        public string $group = '',
    ) {}
}
