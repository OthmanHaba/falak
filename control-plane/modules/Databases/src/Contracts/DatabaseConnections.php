<?php

namespace Kiln\Databases\Contracts;

use Kiln\Databases\Contracts\Data\DatabaseConsumer;

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

    /**
     * Host for an engine on an app/worker server (check {@see unreachable()} for the consumer): 127.0.0.1, or for a
     * containerized consumer (Docker, compose, function) on that server the server's own address (private network,
     * provider private IP, public IP), which its containers reach through the Docker bridge. For a dedicated
     * database server: its private network (WireGuard) address, else its provider private IP, else its public IP.
     * Credentials are those of the oldest user granted access.
     *
     * @param  ?DatabaseConsumer  $consumer  who connects (null: a native consumer)
     * @return array<string, string> empty when the database does not exist
     */
    public function variables(string $databaseId, ?DatabaseConsumer $consumer = null): array;

    /**
     * Why the consumer cannot connect to the host in {@see HOST_KEYS}, or null when it can. An engine on an app/worker
     * server is reachable from that server only: by native sites, and by containers once the server's agent supports
     * container access (feature db.containers) — not from other servers.
     */
    public function unreachable(string $databaseId, DatabaseConsumer $consumer): ?string;
}
