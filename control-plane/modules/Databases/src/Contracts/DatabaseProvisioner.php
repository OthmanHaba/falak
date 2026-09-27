<?php

namespace Kiln\Databases\Contracts;

use Illuminate\Validation\ValidationException;
use Kiln\Databases\Contracts\Data\DatabaseData;

/**
 * Database creation for other modules (Projects' canvas "Create → Database").
 */
interface DatabaseProvisioner
{
    /**
     * Create a database on the server's engine plus a user (same name, generated password) with all
     * privileges on it. The database is `pending` until the agent confirms (then DatabaseCreated).
     *
     * @param  string  $engine  postgresql | mysql | mariadb (must match the server's engine)
     *
     * @throws ValidationException engine / server / name problems (keys: engine, server_id, name)
     */
    public function create(string $organizationId, string $serverId, string $engine, string $name, ?string $actorId = null): DatabaseData;
}
