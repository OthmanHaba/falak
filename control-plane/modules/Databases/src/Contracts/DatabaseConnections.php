<?php

namespace Falak\Databases\Contracts;

use Falak\Databases\Contracts\Data\DatabaseConsumer;

/**
 * Connection variables of a database, for Projects' variable references (`${{ db.DATABASE_URL }}`).
 * The result contains the password: only resolve it into a release environment, never show or log it.
 */
interface DatabaseConnections
{
    /** Keys a database service exposes (DB_USERNAME / DB_PASSWORD / DATABASE_URL need a user with access). */
    public const KEYS = ['DATABASE_URL', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

    /** Keys carrying the host: only usable when {@see unreachable()} is null for the consumer. */
    public const HOST_KEYS = ['DATABASE_URL', 'DB_HOST'];

    /** Keys a Redis / Valkey instance exposes (Laravel's names; the password is the instance's `default` user). */
    public const REDIS_KEYS = ['REDIS_URL', 'REDIS_CLIENT', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD'];

    public const REDIS_HOST_KEYS = ['REDIS_URL', 'REDIS_HOST'];

    /**
     * Keys a database service of this engine exposes.
     *
     * @param  string  $engine  postgresql | mysql | mariadb | redis | valkey
     * @return list<string>
     */
    public function keysFor(string $engine): array;

    /**
     * Keys of {@see keysFor()} that carry the host.
     *
     * @return list<string>
     */
    public function hostKeysFor(string $engine): array;

    /**
     * Host and port for the consumer (check {@see unreachable()} for it), never a public address: containers on the
     * database container's server (Docker sites, compose stacks) get its DNS name on the environment's Docker network
     * (falak-db-<id>) and the engine's port; native sites there 127.0.0.1 and the container's host port; other servers
     * its server's address on a private network they all share with it (WireGuard first, then the provider private
     * network) and the host port. SQL credentials are those of the oldest user granted access; Redis / Valkey
     * ({@see REDIS_KEYS}) authenticate as `default`. An unresolved host is 127.0.0.1 with the host port.
     *
     * @param  ?DatabaseConsumer  $consumer  who connects (null: a native consumer)
     * @return array<string, string> empty when the database does not exist
     */
    public function variables(string $databaseId, ?DatabaseConsumer $consumer = null): array;

    /**
     * Why the consumer cannot connect to the host in {@see HOST_KEYS}, or null when it can: containers need the database
     * in a project environment (its network), other servers a private network all of them share with its server and
     * the port published there (Falak publishes it once such a consumer exists).
     */
    public function unreachable(string $databaseId, DatabaseConsumer $consumer): ?string;
}
