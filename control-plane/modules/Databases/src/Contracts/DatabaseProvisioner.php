<?php

namespace Falak\Databases\Contracts;

use Falak\Databases\Contracts\Data\DatabaseData;
use Illuminate\Validation\ValidationException;

/**
 * Database creation for other modules (Projects' canvas "Create → Database", Sites' compose extraction).
 */
interface DatabaseProvisioner
{
    /**
     * Create a database container on the server (a Falak image of the engine, its data on a new sized volume), joined to
     * the environment's Docker network. SQL engines: with a default database and a user with all privileges on it (named
     * after $name); Redis / Valkey: the keyspace and its `default` user. The returned database is `pending` until the
     * container runs and the database exists (then DatabaseCreated).
     *
     * @param  string  $engine  postgresql | mysql | mariadb | redis | valkey
     *                          Options: `image_tag` is a compose image's tag, mapped to the closest supported major; `database` names the SQL
     *                          default database.
     * @param  array{version?: ?string, image_tag?: ?string, database?: ?string, memory_mb?: ?int, cpus?: ?float, disk_gb?: ?int, environment_id?: ?string, eviction?: ?string, persistence?: ?string, max_connections?: ?int}  $options
     *
     * @throws ValidationException engine / server / name / option problems (keys: engine, server_id, name, version, memory_mb, disk_gb, settings.*)
     */
    public function create(string $organizationId, string $serverId, string $engine, string $name, ?string $actorId = null, array $options = []): DatabaseData;

    /**
     * Delete a database service like the Databases page does: its container goes with it when it is the container's
     * only database (or a Redis / Valkey keyspace); the data volume stays unless $deleteVolume. DatabaseDeleted follows
     * once the agent confirms. Unknown ids are ignored.
     */
    public function delete(string $databaseId, bool $deleteVolume = false): void;
}
