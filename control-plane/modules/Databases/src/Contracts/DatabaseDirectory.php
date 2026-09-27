<?php

namespace Kiln\Databases\Contracts;

use Kiln\Databases\Contracts\Data\DatabaseData;

/**
 * Read-only database lookups for other modules (e.g. Sites showing a site's databases).
 */
interface DatabaseDirectory
{
    public function find(string $databaseId): ?DatabaseData;

    /**
     * @param  list<string>  $databaseIds
     * @return array<string, DatabaseData> keyed by id (unknown ids are omitted)
     */
    public function findMany(array $databaseIds): array;

    /**
     * @return list<DatabaseData>
     */
    public function forOrganization(string $organizationId): array;

    /**
     * @return list<DatabaseData>
     */
    public function forServer(string $serverId): array;

    /**
     * Databases linked to a site (opaque Sites ULID).
     *
     * @return list<DatabaseData>
     */
    public function forSite(string $organizationId, string $siteId): array;
}
