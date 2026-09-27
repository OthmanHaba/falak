<?php

namespace Kiln\Identity\Contracts;

use Kiln\Identity\Contracts\Data\OrganizationData;
use Kiln\Identity\Contracts\Data\UserData;

/**
 * Read-only lookups of organizations and users for other modules.
 */
interface OrganizationDirectory
{
    public function find(string $organizationId): ?OrganizationData;

    /**
     * Every organization, oldest first (maintenance tasks such as data backfills).
     *
     * @return list<OrganizationData>
     */
    public function all(): array;

    public function findUser(string $userId): ?UserData;

    /**
     * @return list<UserData>
     */
    public function members(string $organizationId): array;
}
