<?php

namespace Falak\Databases\Contracts;

use Illuminate\Validation\ValidationException;
use Falak\Databases\Contracts\Data\DatabaseData;

/**
 * Database creation for other modules (Projects' canvas "Create → Database").
 */
interface DatabaseProvisioner
{
    /**
     * Create a database on the server's engine plus a user (same name, generated password) with all
     * privileges on it. The database is `pending` until the agent confirms (then DatabaseCreated).
     * Redis / Valkey: an instance (own process, port and `default` password) on the server's key-value engine; the
     * server's agent must support it (feature db.redis), else a validation error on server_id says to update it.
     *
     * @param  string  $engine  postgresql | mysql | mariadb | redis | valkey (the server must run it)
     * @param  array{maxmemory_mb?: ?int, eviction?: ?string, persistence?: ?string}  $options  Redis / Valkey settings
     *
     * @throws ValidationException engine / server / name problems (keys: engine, server_id, name, maxmemory_mb, eviction, persistence)
     */
    public function create(string $organizationId, string $serverId, string $engine, string $name, ?string $actorId = null, array $options = []): DatabaseData;

    /**
     * Drop a database like the Databases page does (DatabaseDeleted once the agent confirms). Unknown ids are ignored.
     */
    public function delete(string $databaseId): void;
}
