<?php

namespace Falak\Databases\Application\KeyValue;

use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Fleet\Contracts\AgentDirectory;
use Illuminate\Validation\ValidationException;

/**
 * Backups and restores of Redis / Valkey instances (db.backup / db.restore with engine redis | valkey): RDB snapshots
 * taken and loaded by agents with the db.redis.backup feature. Older agents refuse those engines, so nothing is sent
 * to them.
 */
final class KeyValueBackups
{
    public const FEATURE = 'db.redis.backup';

    public function __construct(private readonly AgentDirectory $agents) {}

    /**
     * Why the server can't back up or restore its instances now, or null when it can (SQL engines always can).
     */
    public function unsupported(DatabaseServer $server): ?string
    {
        if (! $server->engine->isKeyValue() || ($this->agents->forServer($server->server_id)?->supports(self::FEATURE) ?? false)) {
            return null;
        }

        return "Update the agent on {$server->server_name} first: backups and restores of {$server->engine->label()} instances need a newer agent (feature ".self::FEATURE.').';
    }

    /**
     * @throws ValidationException
     */
    public function assertSupported(DatabaseServer $server, string $field): void
    {
        if (($reason = $this->unsupported($server)) !== null) {
            throw ValidationException::withMessages([$field => $reason]);
        }
    }
}
