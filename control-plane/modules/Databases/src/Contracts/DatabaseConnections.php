<?php

namespace Kiln\Databases\Contracts;

/**
 * Connection variables of a database, for Projects' variable references (`${{ db.DATABASE_URL }}`).
 * The result contains the password: only resolve it into a release environment, never show or log it.
 */
interface DatabaseConnections
{
    /** Keys a database service exposes (DB_USERNAME / DB_PASSWORD / DATABASE_URL need a user with access). */
    public const KEYS = ['DATABASE_URL', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

    /**
     * Host = the engine server's private network (WireGuard) address, else its provider private IP, else
     * its public IP. Credentials are those of the oldest user granted access to the database.
     *
     * @return array<string, string> empty when the database does not exist
     */
    public function variables(string $databaseId): array;
}
