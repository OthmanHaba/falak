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

    /**
     * Restore the newest successful logical backup of $sourceDatabaseId (Falak-held key) into $targetDatabaseId, which
     * must be active (e.g. a preview's copy of a staging database). RestoreFinished follows with the returned id.
     *
     * @throws ValidationException no such backup, or the target can't take it
     */
    public function restoreLatestBackup(string $sourceDatabaseId, string $targetDatabaseId, ?string $actorId = null): string;

    /**
     * Run a user's script against an active SQL database, inside its container, as the oldest user granted access to
     * it: `sql` through psql / mysql (stopping at the first error; Postgres in one transaction), `command` with sh and
     * the connection in DB_* variables (and PG* / MYSQL_PWD). Fleet's CommandFinished / CommandFailed report it, with the
     * idempotency key `databases.script:<$key>`.
     *
     * @param  'sql'|'command'  $kind
     * @return string the command id
     *
     * @throws ValidationException
     */
    public function runScript(string $databaseId, string $kind, string $script, string $key, int $timeout = 900): string;
}
